<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\BloodUnit;
use App\Models\Dispatch;
use App\Models\DispatchEvent;
use App\Models\Hospital;
use App\Notifications\BloodLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DispatchController extends Controller
{
    private const FILTERS = ['all', Dispatch::STATUS_PREPARING, Dispatch::STATUS_EN_ROUTE, Dispatch::STATUS_DELIVERED];

    public function index(Request $request)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        $filter = in_array($request->query('status'), self::FILTERS, true) ? $request->query('status') : 'all';
        $query = Dispatch::query()->where('hospital_id', $hospital->id)->with(['destination', 'bloodRequest', 'events']);
        if ($filter === 'all') {
            $query->where('status', '!=', Dispatch::STATUS_CANCELLED);
        } else {
            $query->where('status', $filter);
        }

        $dispatches = $query->orderByRaw("CASE status WHEN 'en_route' THEN 0 WHEN 'preparing' THEN 1 ELSE 2 END")
            ->orderBy('eta_at')->orderByDesc('created_at')->paginate(10)->withQueryString();
        $selectedId = $request->integer('selected');
        $selected = $dispatches->getCollection()->firstWhere('id', $selectedId)
            ?? $dispatches->getCollection()->first();
        $selected?->load(['destination', 'bloodRequest.user', 'manifestUnits', 'events']);

        $all = Dispatch::query()->where('hospital_id', $hospital->id)->where('status', '!=', Dispatch::STATUS_CANCELLED)->get();
        $active = $all->whereIn('status', Dispatch::ACTIVE_STATUSES);
        $deliveredToday = $all->where('status', Dispatch::STATUS_DELIVERED)
            ->filter(fn (Dispatch $dispatch) => $dispatch->delivered_at?->isToday())->count();
        $coldAlerts = $active->filter(fn (Dispatch $dispatch) =>
            $dispatch->status === Dispatch::STATUS_EN_ROUTE
            && $dispatch->eta_at
            && $dispatch->eta_at->isPast()
        )->count();
        $nextArrival = $active->where('status', Dispatch::STATUS_EN_ROUTE)
            ->filter(fn (Dispatch $dispatch) => $dispatch->eta_at)
            ->sortBy('eta_at')->first();
        $filterCounts = [
            'all' => $all->count(),
            Dispatch::STATUS_PREPARING => $all->where('status', Dispatch::STATUS_PREPARING)->count(),
            Dispatch::STATUS_EN_ROUTE => $all->where('status', Dispatch::STATUS_EN_ROUTE)->count(),
            Dispatch::STATUS_DELIVERED => $all->where('status', Dispatch::STATUS_DELIVERED)->count(),
        ];

        $readyRequests = BloodRequest::query()
            ->where('hospital_id', $hospital->id)
            ->where('share_authorized_data', true)
            ->where('status', BloodRequest::STATUS_MATCHED)
            ->with('user')
            ->orderByRaw("CASE urgency WHEN 'critical' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderBy('needed_by')
            ->get()
            ->filter(fn (BloodRequest $bloodRequest) => ! Dispatch::query()
                ->where('blood_request_id', $bloodRequest->id)
                ->whereIn('status', [Dispatch::STATUS_PREPARING, Dispatch::STATUS_EN_ROUTE, Dispatch::STATUS_DELIVERED])
                ->exists())
            ->values();

        return view('hospital.dispatch', [
            'user' => $user,
            'hospital' => $hospital,
            'dispatches' => $dispatches,
            'selected' => $selected,
            'filter' => $filter,
            'metrics' => [
                'active' => $active->count(),
                'delivered_today' => $deliveredToday,
                'cold_alerts' => $coldAlerts,
                'next_arrival' => $nextArrival,
            ],
            'filterCounts' => $filterCounts,
            'readyRequests' => $readyRequests,
            'partners' => Hospital::query()->where('is_partner', true)->where('id', '!=', $hospital->id)->orderBy('name')->get(),
            'components' => BloodStock::COMPONENTS,
            'bloodTypes' => config('bloodlink.blood_types'),
            'availableUnits' => BloodUnit::query()
                ->where('hospital_id', $hospital->id)
                ->where('status', BloodUnit::STATUS_AVAILABLE)
                ->where('expires_at', '>=', today())
                ->count(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        $requestLinked = $request->filled('blood_request_id');
        $data = $request->validate([
            'blood_request_id' => [
                'nullable', 'integer',
                Rule::exists('blood_requests', 'id')->where(fn ($query) => $query
                    ->where('hospital_id', $hospital->id)
                    ->where('share_authorized_data', true)
                    ->where('status', BloodRequest::STATUS_MATCHED)),
            ],
            'destination_hospital_id' => [
                'nullable', ...($requestLinked ? [] : ['required_without:destination_name']), 'integer',
                Rule::exists('hospitals', 'id')->where('is_partner', true),
                Rule::notIn([$hospital->id]),
            ],
            'destination_name' => ['nullable', ...($requestLinked ? [] : ['required_without:destination_hospital_id']), 'string', 'max:190'],
            'blood_type' => [$requestLinked ? 'nullable' : 'required', Rule::in(array_keys(config('bloodlink.blood_types')))],
            'component' => [$requestLinked ? 'nullable' : 'required', Rule::in(array_keys(BloodStock::COMPONENTS))],
            'units' => [$requestLinked ? 'nullable' : 'required', 'integer', 'between:1,50'],
            'vehicle' => ['required', 'string', 'max:80'],
            'team' => ['required', 'string', 'max:120'],
            'eta_at' => ['required', 'date', 'after:now'],
        ]);

        $dispatch = DB::transaction(function () use ($data, $hospital, $user) {
            $bloodRequest = null;
            if (! empty($data['blood_request_id'])) {
                $bloodRequest = BloodRequest::query()->lockForUpdate()->findOrFail($data['blood_request_id']);
                $this->authorizeRequestForDispatch($bloodRequest, $hospital);
                if (Dispatch::query()->where('blood_request_id', $bloodRequest->id)
                    ->where('status', '!=', Dispatch::STATUS_CANCELLED)->exists()
                ) {
                    throw ValidationException::withMessages([
                        'blood_request_id' => 'This request already has a dispatch record. Review its existing run before creating another.',
                    ]);
                }
                $data['destination_name'] = $bloodRequest->patient_name;
                $data['blood_type'] = $bloodRequest->blood_type;
                $data['component'] = $bloodRequest->component;
                $data['units'] = $bloodRequest->units;
            }

            $dispatch = Dispatch::create([
                'hospital_id' => $hospital->id,
                'blood_request_id' => $bloodRequest?->id,
                'destination_hospital_id' => $data['destination_hospital_id'] ?? null,
                'destination_name' => $data['destination_name'] ?? null,
                'blood_type' => $data['blood_type'],
                'component' => $data['component'],
                'units' => (int) $data['units'],
                'vehicle' => trim($data['vehicle']),
                'team' => trim($data['team']),
                'status' => Dispatch::STATUS_PREPARING,
                'eta_at' => $data['eta_at'],
            ]);

            if ($bloodRequest) {
                $units = BloodUnit::query()
                    ->where('hospital_id', $hospital->id)
                    ->where('status', BloodUnit::STATUS_RESERVED)
                    ->where('reservation_reference', $bloodRequest->reference)
                    ->where('blood_type', $bloodRequest->blood_type)
                    ->where('component', $bloodRequest->component)
                    ->where('expires_at', '>=', today())
                    ->orderBy('expires_at')->orderBy('unit_code')
                    ->limit($bloodRequest->units)->lockForUpdate()->get();
            } else {
                $units = BloodUnit::query()
                    ->where('hospital_id', $hospital->id)
                    ->where('status', BloodUnit::STATUS_AVAILABLE)
                    ->where('blood_type', $dispatch->blood_type)
                    ->where('component', $dispatch->component)
                    ->where('expires_at', '>=', today())
                    ->orderBy('expires_at')->orderBy('unit_code')
                    ->limit($dispatch->units)->lockForUpdate()->get();
            }

            if ($units->count() !== $dispatch->units) {
                throw ValidationException::withMessages([
                    'dispatch' => "Only {$units->count()} unexpired tracked units are available for this dispatch.",
                ]);
            }

            foreach ($units as $unit) {
                if (! $bloodRequest) {
                    $unit->update([
                        'status' => BloodUnit::STATUS_RESERVED,
                        'reserved_for' => $dispatch->destinationLabel(),
                        'reservation_reference' => 'DSP-' . $dispatch->id,
                    ]);
                } else {
                    $unit->update([
                        'reserved_for' => $dispatch->destinationLabel(),
                        'reservation_reference' => 'DSP-' . $dispatch->id,
                    ]);
                }
            }
            $dispatch->manifestUnits()->sync($units->modelKeys());
            BloodUnit::syncStocks($hospital->id);

            $this->recordEvent($dispatch, $user, 'prepared', 'Dispatch created and tracked units reserved.', [
                'unit_codes' => $units->pluck('unit_code')->all(),
                'destination' => $dispatch->destinationLabel(),
            ]);
            $this->log($hospital, $user, 'Dispatch prepared', $dispatch, 'coordinating');

            return $dispatch;
        });

        return redirect()->route('hospital.dispatch', ['selected' => $dispatch->id])
            ->with('status', "Dispatch for {$dispatch->destinationLabel()} created. Units are reserved pending handoff.");
    }

    public function release(Request $request, Dispatch $dispatch)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($dispatch->hospital_id === $hospital->id, 404);
        $data = $request->validate([
            'sender_confirmed' => ['accepted'],
            'vehicle_checked' => ['accepted'],
            'cold_chain_intact' => ['accepted'],
            'team_confirmed' => ['accepted'],
            'temperature' => ['required', 'numeric', 'between:-80,30'],
        ], [
            'cold_chain_intact.accepted' => 'Confirm the product-specific cold-chain procedure before release.',
            'temperature.required' => 'Record the handoff temperature before release.',
        ]);

        DB::transaction(function () use ($dispatch, $hospital, $user, $data) {
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Dispatch::STATUS_PREPARING) {
                throw ValidationException::withMessages(['dispatch' => 'Only a preparing dispatch can be released.']);
            }
            $requestRecord = $locked->blood_request_id
                ? BloodRequest::query()->whereKey($locked->blood_request_id)->lockForUpdate()->first()
                : null;
            if ($locked->blood_request_id && (! $requestRecord || ! $requestRecord->share_authorized_data
                || $requestRecord->status !== BloodRequest::STATUS_MATCHED
            )) {
                throw ValidationException::withMessages(['dispatch' => 'The linked request is no longer active and consented. Do not release this dispatch.']);
            }
            if ($requestRecord) {
                foreach (['physician_order', 'abo_confirmed', 'compatibility_completed', 'release_authorized'] as $check) {
                    if (! $requestRecord->reviewCheck($check)) {
                        throw ValidationException::withMessages(['dispatch' => 'Complete and save every clinical verification and release authorization checklist item before dispatch.']);
                    }
                }
                if (! $requestRecord->documents()
                    ->where('kind', 'physician_request')
                    ->where('status', '!=', 'rejected')
                    ->exists()
                ) {
                    throw ValidationException::withMessages(['dispatch' => 'An active physician order must be attached before dispatch.']);
                }
            }
            $units = $locked->manifestUnits()->lockForUpdate()->get();
            if ($units->count() !== $locked->units || $units->contains(fn (BloodUnit $unit) =>
                $unit->hospital_id !== $hospital->id
                || $unit->status !== BloodUnit::STATUS_RESERVED
                || $unit->reservation_reference !== 'DSP-' . $locked->id
                || $unit->isExpired()
            )) {
                throw ValidationException::withMessages(['dispatch' => 'The unit manifest is incomplete or no longer safe to release. Review inventory and prepare the dispatch again.']);
            }

            foreach ($units as $unit) {
                $unit->update(['status' => BloodUnit::STATUS_DISPATCHED]);
            }
            $locked->update([
                'status' => Dispatch::STATUS_EN_ROUTE,
                'dispatched_at' => now(),
                'cold_chain_temp' => (float) $data['temperature'],
            ]);
            if ($requestRecord) {
                $requestRecord->update([
                    'status' => BloodRequest::STATUS_IN_TRANSIT,
                    'in_transit_at' => now(),
                ]);
                if ($requestRecord->share_authorized_data && $requestRecord->user) {
                    $requestRecord->user->notify(new BloodLinkNotification(
                        'Blood request is in transit',
                        $requestRecord->reference . ' has been released to the transport team by ' . $hospital->name . '. Follow delivery instructions from the facility; this is not a pickup notice.',
                        route('receiver.requests.show', $requestRecord, false),
                        'request'
                    ));
                }
            }
            BloodUnit::syncStocks($hospital->id);
            $this->recordEvent($locked, $user, 'released', 'Transport team accepted custody and cold-chain checks were recorded.', [
                'sender_confirmed' => true,
                'vehicle_checked' => true,
                'cold_chain_intact' => true,
                'team_confirmed' => true,
                'unit_codes' => $units->pluck('unit_code')->all(),
            ], (float) $data['temperature']);
            $this->log($hospital, $user, 'Dispatch released', $locked, 'in_transit');
        });

        return redirect()->route('hospital.dispatch', ['selected' => $dispatch->id])
            ->with('status', 'Dispatch released to the transport team.');
    }

    public function deliver(Request $request, Dispatch $dispatch)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($dispatch->hospital_id === $hospital->id, 404);
        $data = $request->validate([
            'receiving_staff' => ['required', 'string', 'max:120'],
            'temperature' => ['required', 'numeric', 'between:-80,30'],
            'identity_confirmed' => ['accepted'],
            'units_reconciled' => ['accepted'],
            'cold_chain_intact' => ['accepted'],
            'storage_ready' => ['accepted'],
        ], [
            'cold_chain_intact.accepted' => 'Confirm the product-specific cold-chain procedure before delivery.',
        ]);

        DB::transaction(function () use ($dispatch, $hospital, $user, $data) {
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Dispatch::STATUS_EN_ROUTE) {
                throw ValidationException::withMessages(['dispatch' => 'Only an en-route dispatch can be marked delivered.']);
            }
            $requestRecord = $locked->blood_request_id
                ? BloodRequest::query()->whereKey($locked->blood_request_id)->lockForUpdate()->first()
                : null;
            $units = $locked->manifestUnits()->lockForUpdate()->get();
            if ($units->count() !== $locked->units || $units->contains(fn (BloodUnit $unit) =>
                $unit->hospital_id !== $hospital->id
                || $unit->status !== BloodUnit::STATUS_DISPATCHED
                || $unit->isExpired()
            )) {
                throw ValidationException::withMessages(['dispatch' => 'The unit manifest is incomplete or contains expired units. Quarantine affected units and coordinate replacement with the blood bank before completing delivery.']);
            }

            $locked->update([
                'status' => Dispatch::STATUS_DELIVERED,
                'delivered_at' => now(),
            ]);
            if ($requestRecord) {
                if ($requestRecord->status !== BloodRequest::STATUS_IN_TRANSIT) {
                    throw ValidationException::withMessages(['dispatch' => 'The linked request is no longer in transit. Review the request before recording delivery.']);
                }
                $requestRecord->update([
                    'status' => BloodRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
                if ($requestRecord->share_authorized_data && $requestRecord->user) {
                    $requestRecord->user->notify(new BloodLinkNotification(
                        'Blood request delivery recorded',
                        $requestRecord->reference . ' was marked delivered by ' . $hospital->name . '.',
                        route('receiver.requests.show', $requestRecord, false),
                        'request'
                    ));
                }
            }
            $this->recordEvent($locked, $user, 'delivered', 'Receiving identity, unit reconciliation, cold-chain, and storage checks confirmed.', [
                'receiving_staff' => trim($data['receiving_staff']),
                'identity_confirmed' => true,
                'units_reconciled' => true,
                'cold_chain_intact' => true,
                'storage_ready' => true,
                'unit_codes' => $units->pluck('unit_code')->all(),
            ], (float) $data['temperature']);
            $this->log($hospital, $user, 'Dispatch delivered', $locked, 'completed');
        });

        return redirect()->route('hospital.dispatch', ['selected' => $dispatch->id])
            ->with('status', 'Delivery and receiving checks were recorded.');
    }

    public function cancel(Request $request, Dispatch $dispatch)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($dispatch->hospital_id === $hospital->id, 404);

        DB::transaction(function () use ($dispatch, $hospital, $user) {
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Dispatch::STATUS_PREPARING) {
                throw ValidationException::withMessages(['dispatch' => 'Only a preparing dispatch can be cancelled.']);
            }
            $requestRecord = $locked->blood_request_id
                ? BloodRequest::query()->with('user')->whereKey($locked->blood_request_id)->first()
                : null;
            foreach ($locked->manifestUnits()->lockForUpdate()->get() as $unit) {
                if ($unit->status !== BloodUnit::STATUS_RESERVED
                    || $unit->reservation_reference !== 'DSP-' . $locked->id
                ) {
                    throw ValidationException::withMessages(['dispatch' => 'A reserved unit no longer belongs to this dispatch. Review the manifest before cancelling.']);
                }
                $restoreRequestReservation = $requestRecord
                    && $requestRecord->share_authorized_data
                    && $requestRecord->status === BloodRequest::STATUS_MATCHED
                    && ! $unit->isExpired();
                $available = ! $restoreRequestReservation && ! $unit->isExpired();
                $unit->update([
                    'status' => $restoreRequestReservation
                        ? BloodUnit::STATUS_RESERVED
                        : ($available ? BloodUnit::STATUS_AVAILABLE : BloodUnit::STATUS_DISCARDED),
                    'reserved_for' => $restoreRequestReservation ? $requestRecord->user?->displayName() : null,
                    'reservation_reference' => $restoreRequestReservation ? $requestRecord->reference : null,
                ]);
            }
            $locked->update(['status' => Dispatch::STATUS_CANCELLED]);
            BloodUnit::syncStocks($hospital->id);
            $this->recordEvent($locked, $user, 'cancelled', 'Preparing dispatch cancelled and reservations released.');
            $this->log($hospital, $user, 'Dispatch cancelled', $locked, 'logged');
        });

        return redirect()->route('hospital.dispatch')->with('status', 'Preparing dispatch cancelled; reserved units were returned to inventory.');
    }

    public function export(Request $request): StreamedResponse
    {
        $hospital = $request->user()->ensureFacility();
        $dispatches = Dispatch::query()->with(['destination', 'bloodRequest'])
            ->where('hospital_id', $hospital->id)
            ->where('status', '!=', Dispatch::STATUS_CANCELLED)
            ->orderByDesc('created_at')->get();

        return response()->streamDownload(function () use ($dispatches) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Dispatch ID', 'Priority', 'Destination', 'Blood group', 'Component', 'Units', 'Vehicle', 'Team', 'Status', 'ETA', 'Dispatched', 'Delivered']);
            foreach ($dispatches as $dispatch) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $dispatch->id,
                    $dispatch->bloodRequest?->priority() ?? 'routine',
                    $dispatch->destinationLabel(),
                    str_replace('-', '−', $dispatch->blood_type),
                    BloodStock::COMPONENTS[$dispatch->component] ?? $dispatch->component,
                    $dispatch->units, $dispatch->vehicle, $dispatch->team, $dispatch->statusLabel(),
                    $dispatch->eta_at?->format('Y-m-d H:i'),
                    $dispatch->dispatched_at?->format('Y-m-d H:i'),
                    $dispatch->delivered_at?->format('Y-m-d H:i'),
                ]));
            }
            fclose($output);
        }, 'bloodlink-dispatch-board-' . now()->format('Ymd') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeRequestForDispatch(BloodRequest $bloodRequest, Hospital $hospital): void
    {
        if ($bloodRequest->hospital_id !== $hospital->id
            || ! $bloodRequest->share_authorized_data
            || $bloodRequest->status !== BloodRequest::STATUS_MATCHED
        ) {
            throw ValidationException::withMessages(['blood_request_id' => 'Select an active, consented request already accepted by this facility.']);
        }
    }

    private function recordEvent(
        Dispatch $dispatch,
        $user,
        string $type,
        string $details,
        array $checklist = [],
        ?float $temperature = null
    ): void {
        DispatchEvent::create([
            'dispatch_id' => $dispatch->id,
            'actor_user_id' => $user->id,
            'event_type' => $type,
            'actor_name' => $user->name,
            'details' => $details,
            'temperature' => $temperature,
            'checklist' => $checklist ?: null,
        ]);
    }

    private function log(Hospital $hospital, $user, string $activity, Dispatch $dispatch, string $status): void
    {
        ActivityLog::create([
            'hospital_id' => $hospital->id,
            'activity' => $activity,
            'details' => Str::limit($dispatch->destinationLabel() . ' · ' . $dispatch->units . ' ' . Str::plural('unit', $dispatch->units), 250, ''),
            'operator' => $user->name,
            'status' => $status,
        ]);
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
