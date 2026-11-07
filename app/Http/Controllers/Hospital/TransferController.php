<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BloodStock;
use App\Models\BloodUnit;
use App\Models\Hospital;
use App\Models\Transfer;
use App\Models\TransferEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransferController extends Controller
{
    private const DIRECTIONS = ['all', 'inbound', 'outbound'];

    public function index(Request $request)
    {
        $hospital = $request->user()->ensureFacility();
        $filters = $this->validatedFilters($request);
        $query = $this->filteredTransfers($hospital->id, $filters);
        $transfers = $query->paginate(8)->withQueryString();
        $selectedId = $request->integer('selected');
        $selected = $selectedId
            ? $transfers->getCollection()->firstWhere('id', $selectedId)
            : null;
        $selected ??= $transfers->getCollection()->first();
        $selected?->load(['from', 'to', 'unitsOnTransfer', 'events.actorHospital']);

        $availableWholeBlood = BloodUnit::query()
            ->where('hospital_id', $hospital->id)
            ->where('component', BloodStock::COMPONENT_WHOLE_BLOOD)
            ->where('status', BloodUnit::STATUS_AVAILABLE)
            ->where('expires_at', '>=', today())
            ->count();

        $inTransit = Transfer::query()
            ->where('status', Transfer::STATUS_IN_TRANSIT)
            ->where(fn (Builder $builder) => $builder
                ->where('from_hospital_id', $hospital->id)
                ->orWhere('to_hospital_id', $hospital->id))
            ->get();

        $lastDelivery = Transfer::query()
            ->where('to_hospital_id', $hospital->id)
            ->where('status', Transfer::STATUS_COMPLETED)
            ->with(['from'])
            ->orderByDesc('received_at')
            ->first();

        $partners = Hospital::query()
            ->where('is_partner', true)
            ->where('id', '!=', $hospital->id)
            ->orderBy('name')
            ->get();

        $availableByGroup = BloodUnit::query()
            ->selectRaw('blood_type, component, COUNT(*) AS units')
            ->where('hospital_id', $hospital->id)
            ->where('status', BloodUnit::STATUS_AVAILABLE)
            ->where('expires_at', '>=', today())
            ->groupBy('blood_type', 'component')
            ->get()
            ->keyBy(fn ($row) => $row->blood_type . '|' . $row->component);

        $stats = [
            'available_whole_blood' => $availableWholeBlood,
            'inbound_units' => $inTransit->where('to_hospital_id', $hospital->id)->sum('units'),
            'in_transit_units' => $inTransit->sum('units'),
            'outbound_units' => $inTransit->where('from_hospital_id', $hospital->id)->sum('units'),
            'last_delivery' => $lastDelivery,
            'direction_counts' => [
                'all' => Transfer::query()
                    ->where(fn (Builder $builder) => $builder
                        ->where('from_hospital_id', $hospital->id)
                        ->orWhere('to_hospital_id', $hospital->id))
                    ->count(),
                'inbound' => Transfer::query()->where('to_hospital_id', $hospital->id)->count(),
                'outbound' => Transfer::query()->where('from_hospital_id', $hospital->id)->count(),
            ],
        ];

        return view('hospital.transfers', [
            'hospital' => $hospital,
            'filters' => $filters,
            'transfers' => $transfers,
            'selected' => $selected,
            'partners' => $partners,
            'components' => BloodStock::COMPONENTS,
            'bloodTypes' => config('bloodlink.blood_types'),
            'availableByGroup' => $availableByGroup,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        $data = $request->validate([
            'direction' => ['required', Rule::in(['inbound', 'outbound'])],
            'partner_hospital_id' => [
                'required',
                'integer',
                Rule::exists('hospitals', 'id')->where('is_partner', true),
                Rule::notIn([$hospital->id]),
            ],
            'blood_type' => ['required', Rule::in(array_keys(config('bloodlink.blood_types')))],
            'component' => ['required', Rule::in(array_keys(BloodStock::COMPONENTS))],
            'units' => ['required', 'integer', 'between:1,50'],
            'eta_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $fromId = $data['direction'] === 'inbound' ? (int) $data['partner_hospital_id'] : $hospital->id;
        $toId = $data['direction'] === 'inbound' ? $hospital->id : (int) $data['partner_hospital_id'];
        $transfer = DB::transaction(function () use ($data, $hospital, $user, $fromId, $toId) {
            $counterpart = Hospital::findOrFail($data['direction'] === 'inbound' ? $fromId : $toId);
            $transfer = Transfer::create([
                'reference' => Transfer::generateReference(),
                'from_hospital_id' => $fromId,
                'to_hospital_id' => $toId,
                'requested_by_hospital_id' => $hospital->id,
                'requested_by_user_id' => $user->id,
                'blood_type' => $data['blood_type'],
                'component' => $data['component'],
                'units' => (int) $data['units'],
                'reason' => trim($data['reason']),
                'status' => Transfer::STATUS_PENDING,
                'eta_at' => $data['eta_at'] ?? null,
            ]);

            $this->recordEvent(
                $transfer,
                $hospital,
                $user,
                'requested',
                $data['direction'] === 'inbound'
                    ? 'Inbound request sent to ' . $counterpart->name . '.'
                    : 'Outbound transfer request created for ' . $counterpart->name . '.',
                ['units_requested' => (int) $data['units']]
            );
            $this->logActivity($hospital, 'Transfer request created', $transfer, 'coordinating');
            $this->logActivity($counterpart, 'Transfer request received', $transfer, 'coordinating');

            return $transfer;
        });

        return redirect()->route('hospital.transfers', ['selected' => $transfer->id])
            ->with('status', "Transfer {$transfer->reference} was created. Stock only moves after the sending facility dispatches it.");
    }

    public function dispatch(Request $request, Transfer $transfer)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($transfer->from_hospital_id === $hospital->id, 403);

        $data = $request->validate([
            'container_sealed' => ['required', 'accepted'],
            'crew_accepted' => ['required', 'accepted'],
            'cold_chain_temp' => ['required', 'numeric', 'between:-80,30'],
            'eta_at' => ['nullable', 'date', 'after:now'],
        ], [
            'container_sealed.accepted' => 'Confirm the transport container is sealed before dispatch.',
            'crew_accepted.accepted' => 'Confirm the receiving courier has accepted custody.',
            'cold_chain_temp.required' => 'Record the cold-chain temperature at handoff.',
            'cold_chain_temp.between' => 'Enter a temperature between -80 and 30 °C.',
        ]);

        DB::transaction(function () use ($transfer, $user, $hospital, $data) {
            $lockedTransfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($lockedTransfer->status !== Transfer::STATUS_PENDING) {
                throw ValidationException::withMessages(['transfer' => 'This transfer is no longer waiting for dispatch.']);
            }

            $units = BloodUnit::query()
                ->where('hospital_id', $hospital->id)
                ->where('blood_type', $lockedTransfer->blood_type)
                ->where('component', $lockedTransfer->component)
                ->where('status', BloodUnit::STATUS_AVAILABLE)
                ->where('expires_at', '>=', today())
                ->orderBy('expires_at')
                ->orderBy('unit_code')
                ->limit($lockedTransfer->units)
                ->lockForUpdate()
                ->get();

            if ($units->count() < $lockedTransfer->units) {
                throw ValidationException::withMessages([
                    'transfer' => "Only {$units->count()} unexpired, unreserved units are available; {$lockedTransfer->units} were requested.",
                ]);
            }

            BloodUnit::syncStocks($hospital->id);
            $stock = BloodStock::query()
                ->where('hospital_id', $hospital->id)
                ->where('blood_type', $lockedTransfer->blood_type)
                ->where('component', $lockedTransfer->component)
                ->first();
            if ($stock && $stock->units - $units->count() < $stock->minimum_reserve) {
                throw ValidationException::withMessages([
                    'transfer' => "Dispatch would reduce this group below the {$stock->minimum_reserve}-unit minimum reserve.",
                ]);
            }

            foreach ($units as $unit) {
                $unit->update([
                    'status' => BloodUnit::STATUS_DISPATCHED,
                    'reserved_for' => $lockedTransfer->to->name,
                    'reservation_reference' => $lockedTransfer->reference,
                ]);
                $lockedTransfer->unitsOnTransfer()->attach($unit->id);
            }

            $lockedTransfer->forceFill([
                'status' => Transfer::STATUS_IN_TRANSIT,
                'dispatched_at' => now(),
                'eta_at' => $data['eta_at'] ?? $lockedTransfer->eta_at,
            ])->save();

            BloodUnit::syncStocks($hospital->id);
            $this->recordEvent(
                $lockedTransfer,
                $hospital,
                $user,
                'dispatched',
                $units->count() . ' units were sealed and handed to the receiving courier.',
                [
                    'unit_codes' => $units->pluck('unit_code')->all(),
                    'container_sealed' => true,
                    'crew_accepted' => true,
                ],
                (float) $data['cold_chain_temp']
            );
            $this->logActivity($hospital, 'Transfer dispatched', $lockedTransfer, 'in_transit');
        });

        return redirect()->route('hospital.transfers', ['selected' => $transfer->id])
            ->with('status', "Transfer {$transfer->reference} is in transit. Receiving facility confirmation is still required.");
    }

    public function receive(Request $request, Transfer $transfer)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($transfer->to_hospital_id === $hospital->id, 403);

        $data = $request->validate([
            'receiving_staff' => ['required', 'string', 'max:120'],
            'cold_chain_temp' => ['required', 'numeric', 'between:-80,30'],
            'identity_confirmed' => ['required', 'accepted'],
            'units_reconciled' => ['required', 'accepted'],
            'cold_chain_intact' => ['required', 'accepted'],
            'storage_ready' => ['required', 'accepted'],
        ], [
            'identity_confirmed.accepted' => 'Confirm the receiving staff identity before receipt.',
            'units_reconciled.accepted' => 'Reconcile the received units with the transfer record.',
            'cold_chain_intact.accepted' => 'Do not complete receipt unless the cold chain remained intact; contact the sending facility to resolve an exception.',
            'storage_ready.accepted' => 'Confirm receiving storage is ready before accepting the units.',
        ]);

        DB::transaction(function () use ($transfer, $user, $hospital, $data) {
            $lockedTransfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($lockedTransfer->status !== Transfer::STATUS_IN_TRANSIT) {
                throw ValidationException::withMessages(['transfer' => 'This transfer is not awaiting receipt.']);
            }

            $units = $lockedTransfer->unitsOnTransfer()
                ->where('blood_units.status', BloodUnit::STATUS_DISPATCHED)
                ->where('blood_units.reservation_reference', $lockedTransfer->reference)
                ->lockForUpdate()
                ->get();
            if ($units->count() !== $lockedTransfer->units) {
                throw ValidationException::withMessages([
                    'transfer' => 'The unit manifest does not match the requested quantity. Reconcile with the sending facility before receipt.',
                ]);
            }

            $expiredUnitCodes = [];
            foreach ($units as $unit) {
                $expired = $unit->expires_at->lt(today());
                if ($expired) {
                    $expiredUnitCodes[] = $unit->unit_code;
                }
                $unit->update([
                    'hospital_id' => $hospital->id,
                    'status' => $expired ? BloodUnit::STATUS_DISCARDED : BloodUnit::STATUS_AVAILABLE,
                    'reserved_for' => null,
                    'reservation_reference' => null,
                    'temperature' => (float) $data['cold_chain_temp'],
                ]);
            }

            $lockedTransfer->forceFill([
                'status' => Transfer::STATUS_COMPLETED,
                'received_at' => now(),
                'delivered_at' => now(),
                'receiving_staff' => trim($data['receiving_staff']),
            ])->save();

            BloodUnit::syncStocks($lockedTransfer->from_hospital_id);
            BloodUnit::syncStocks($hospital->id);
            $receivedCount = $units->count();
            $expiredCount = count($expiredUnitCodes);
            $availableCount = $receivedCount - $expiredCount;
            $this->recordEvent(
                $lockedTransfer,
                $hospital,
                $user,
                'received',
                $receivedCount . ' ' . Str::plural('unit', $receivedCount) . ' reconciled and cold-chain checked; '
                    . $availableCount . ' ' . Str::plural('unit', $availableCount) . ' added to available stock and '
                    . $expiredCount . ' expired ' . Str::plural('unit', $expiredCount) . ' excluded from stock.',
                [
                    'receiving_staff' => trim($data['receiving_staff']),
                    'identity_confirmed' => true,
                    'units_reconciled' => true,
                    'cold_chain_intact' => true,
                    'storage_ready' => true,
                    'unit_codes' => $units->pluck('unit_code')->all(),
                    'expired_unit_codes' => $expiredUnitCodes,
                ],
                (float) $data['cold_chain_temp']
            );
            $this->logActivity($hospital, 'Transfer received', $lockedTransfer, 'completed');
            $this->logActivity($lockedTransfer->from, 'Transfer delivered', $lockedTransfer, 'completed');
        });

        return redirect()->route('hospital.transfers', ['selected' => $transfer->id])
            ->with('status', "Transfer {$transfer->reference} was received and added to inventory.");
    }

    public function cancel(Request $request, Transfer $transfer)
    {
        $hospital = $request->user()->ensureFacility();
        abort_unless($transfer->requested_by_hospital_id === $hospital->id, 403);
        if ($transfer->status !== Transfer::STATUS_PENDING) {
            throw ValidationException::withMessages(['transfer' => 'A transfer can only be cancelled before it is dispatched.']);
        }

        DB::transaction(function () use ($transfer, $request, $hospital) {
            $lockedTransfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($lockedTransfer->status !== Transfer::STATUS_PENDING) {
                throw ValidationException::withMessages(['transfer' => 'This transfer has already been dispatched.']);
            }
            $lockedTransfer->update(['status' => Transfer::STATUS_CANCELLED]);
            $this->recordEvent($lockedTransfer, $hospital, $request->user(), 'cancelled', 'Pending transfer request cancelled before dispatch.');
        });

        return redirect()->route('hospital.transfers', ['selected' => $transfer->id])
            ->with('status', "Transfer {$transfer->reference} was cancelled before dispatch.");
    }

    public function export(Request $request): StreamedResponse
    {
        $hospital = $request->user()->ensureFacility();
        $filters = $this->validatedFilters($request);
        $transfers = $this->filteredTransfers($hospital->id, $filters)
            ->orderByDesc('created_at')
            ->get();

        return response()->streamDownload(function () use ($transfers, $hospital) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Transfer ID', 'Direction', 'Partner facility', 'Blood group', 'Component', 'Units', 'Status', 'Reason', 'Created', 'ETA', 'Delivered']);
            foreach ($transfers as $transfer) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $transfer->reference,
                    $transfer->directionFor($hospital->id),
                    $transfer->counterpartFor($hospital->id)?->name,
                    str_replace('-', '−', $transfer->blood_type),
                    BloodStock::COMPONENTS[$transfer->component] ?? $transfer->component,
                    $transfer->units,
                    $transfer->statusLabelFor($hospital->id),
                    $transfer->reason,
                    $transfer->created_at?->format('Y-m-d H:i'),
                    $transfer->eta_at?->format('Y-m-d H:i'),
                    $transfer->received_at?->format('Y-m-d H:i'),
                ]));
            }
            fclose($output);
        }, 'bloodlink-transfer-log-' . now()->format('Ymd') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'direction' => ['nullable', Rule::in(self::DIRECTIONS)],
            'status' => ['nullable', Rule::in(['all', 'pending', 'in_transit', 'completed', 'cancelled'])],
            'q' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date'],
            'selected' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'direction' => $data['direction'] ?? 'all',
            'status' => $data['status'] ?? 'all',
            'q' => trim($data['q'] ?? ''),
            'date' => $data['date'] ?? '',
        ];
    }

    private function filteredTransfers(int $hospitalId, array $filters): Builder
    {
        $query = Transfer::query()
            ->with(['from', 'to'])
            ->where(fn (Builder $builder) => $builder
                ->where('from_hospital_id', $hospitalId)
                ->orWhere('to_hospital_id', $hospitalId));

        if ($filters['direction'] === 'inbound') {
            $query->where('to_hospital_id', $hospitalId);
        } elseif ($filters['direction'] === 'outbound') {
            $query->where('from_hospital_id', $hospitalId);
        }

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if ($filters['q'] !== '') {
            $search = $filters['q'];
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('reference', 'like', '%' . $search . '%')
                    ->orWhere('blood_type', 'like', '%' . $search . '%')
                    ->orWhere('reason', 'like', '%' . $search . '%')
                    ->orWhereHas('from', fn (Builder $facility) => $facility->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('to', fn (Builder $facility) => $facility->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($filters['date'] !== '') {
            $date = \Illuminate\Support\Carbon::parse($filters['date']);
            $query->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    private function recordEvent(
        Transfer $transfer,
        Hospital $hospital,
        $user,
        string $type,
        string $details,
        array $checklist = [],
        ?float $temperature = null
    ): void {
        TransferEvent::create([
            'transfer_id' => $transfer->id,
            'actor_hospital_id' => $hospital->id,
            'actor_user_id' => $user->id,
            'event_type' => $type,
            'actor_name' => $user->name,
            'details' => $details,
            'temperature' => $temperature,
            'checklist' => $checklist ?: null,
        ]);
    }

    private function logActivity(?Hospital $hospital, string $activity, Transfer $transfer, string $status): void
    {
        if (! $hospital) {
            return;
        }

        ActivityLog::create([
            'hospital_id' => $hospital->id,
            'activity' => $activity,
            'details' => Str::limit($transfer->reference . ' · ' . $transfer->units . ' ' . Str::plural('unit', $transfer->units), 250, ''),
            'operator' => 'Transfer workflow',
            'status' => $status,
        ]);
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
