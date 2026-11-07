<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\Dispatch;
use App\Models\Hospital;
use App\Models\HospitalComplianceCheck;
use App\Models\RequestDocument;
use App\Models\Transfer;
use App\Models\TransferEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsComplianceController extends Controller
{
    private const CHECKS = [
        'facility_license' => [
            'title' => 'Review facility license copy',
            'detail' => 'Confirm facility accreditation details remain current.',
            'cadence' => 'Facility credential review',
        ],
        'phi_protocol' => [
            'title' => 'Complete PHI handling review',
            'detail' => 'Confirm staff follow the facility patient-information access protocol.',
            'cadence' => 'Privacy and access control',
        ],
        'cold_chain_calibration' => [
            'title' => 'Review cold-chain logger records',
            'detail' => 'Verify temperature logs and calibration records with your facility procedure.',
            'cadence' => 'Storage and transport checks',
        ],
        'audit_handover' => [
            'title' => 'Prepare audit handover snapshot',
            'detail' => 'Export the current stock and activity reports for your internal audit.',
            'cadence' => 'Audit handover',
        ],
    ];

    private const REPORTS = [
        'inventory_snapshot' => 'Whole-blood stock snapshot',
        'transfer_log' => 'Inter-facility transfer log',
        'cold_chain_evidence' => 'Cold-chain evidence packet',
        'credential_checklist' => 'Credential review checklist',
        'audit_activity' => 'Facility audit activity log',
    ];

    public function index(Request $request)
    {
        $hospital = $request->user()->ensureFacility();
        $filters = $this->validatedFilters($request);
        [$start, $end] = $this->period($filters);

        $stocks = $hospital->stocks()
            ->where('component', $filters['component'])
            ->get()
            ->keyBy('blood_type');
        $bloodTypes = array_keys(config('bloodlink.blood_types'));
        $composition = collect($bloodTypes)->map(fn ($type) => [
            'type' => $type,
            'stock' => $stocks->get($type),
            'units' => (int) ($stocks->get($type)?->units ?? 0),
        ]);
        $maxUnits = max(1, (int) $composition->max('units'));

        $inbound = Transfer::query()
            ->where('to_hospital_id', $hospital->id)
            ->whereIn('status', [Transfer::STATUS_PENDING, Transfer::STATUS_IN_TRANSIT])
            ->get();
        $outboundDispatches = Dispatch::query()
            ->where('hospital_id', $hospital->id)
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$start, $end])
            ->where('status', '!=', Dispatch::STATUS_CANCELLED)
            ->get();
        $outboundTransfers = Transfer::query()
            ->where('from_hospital_id', $hospital->id)
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$start, $end])
            ->whereIn('status', [Transfer::STATUS_IN_TRANSIT, Transfer::STATUS_COMPLETED])
            ->get();
        $handoffTransfers = Transfer::query()
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$start, $end])
            ->where(fn (Builder $query) => $query
                ->where('from_hospital_id', $hospital->id)
                ->orWhere('to_hospital_id', $hospital->id))
            ->whereIn('status', [Transfer::STATUS_IN_TRANSIT, Transfer::STATUS_COMPLETED])
            ->get(['id', 'status']);
        $confirmedHandoffs = $handoffTransfers->where('status', Transfer::STATUS_COMPLETED)->count();

        $dispatchesInPeriod = Dispatch::query()
            ->where('hospital_id', $hospital->id)
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$start, $end])
            ->where('status', '!=', Dispatch::STATUS_CANCELLED)
            ->get(['status', 'delivered_at']);
        $deliveredDispatches = $dispatchesInPeriod->where('status', Dispatch::STATUS_DELIVERED)->count();

        $latestDispatch = Dispatch::query()
            ->where('hospital_id', $hospital->id)
            ->whereNotNull('dispatched_at')
            ->where('status', '!=', Dispatch::STATUS_CANCELLED)
            ->orderByDesc('dispatched_at')
            ->first();

        $coldChainEvents = $this->coldChainEvents($hospital->id, $start, $end)->limit(5)->get();
        $activity = $this->activityRows($hospital->id, $start, $end);
        $checks = HospitalComplianceCheck::query()->where('hospital_id', $hospital->id)
            ->get()->keyBy('check_key');

        $requestIds = BloodRequest::query()
            ->where('hospital_id', $hospital->id)
            ->where('share_authorized_data', true)
            ->whereNotIn('status', [BloodRequest::STATUS_DRAFT, BloodRequest::STATUS_PAUSED])
            ->select('id');
        $documentCounts = RequestDocument::query()
            ->whereIn('blood_request_id', $requestIds)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $documentsNeedingReview = (int) ($documentCounts['review_required'] ?? 0);
        $reportCounts = $this->reportCounts($hospital->id, $filters, $start, $end);

        return view('hospital.reports', [
            'hospital' => $hospital,
            'filters' => $filters,
            'componentName' => BloodStock::COMPONENTS[$filters['component']],
            'components' => BloodStock::COMPONENTS,
            'composition' => $composition,
            'maxUnits' => $maxUnits,
            'availableWholeBlood' => (int) $hospital->stocks()
                ->where('component', BloodStock::COMPONENT_WHOLE_BLOOD)->sum('units'),
            'inboundUnits' => (int) $inbound->sum('units'),
            'inboundRequestCount' => $inbound->where('status', Transfer::STATUS_PENDING)->count(),
            'outboundUnits' => (int) $outboundDispatches->sum('units') + (int) $outboundTransfers->sum('units'),
            'handoffTotal' => $handoffTransfers->count(),
            'confirmedHandoffs' => $confirmedHandoffs,
            'deliveredDispatches' => $deliveredDispatches,
            'dispatchTotal' => $dispatchesInPeriod->count(),
            'latestDispatch' => $latestDispatch,
            'coldChainEvents' => $coldChainEvents,
            'activity' => $activity,
            'checks' => $checks,
            'checkDefinitions' => self::CHECKS,
            'documentsNeedingReview' => $documentsNeedingReview,
            'verifiedDocuments' => (int) ($documentCounts['verified'] ?? 0),
            'reportDefinitions' => self::REPORTS,
            'reportCounts' => $reportCounts,
        ]);
    }

    public function updateCheck(Request $request, string $check)
    {
        abort_unless(array_key_exists($check, self::CHECKS), 404);
        $data = $request->validate(['completed' => ['required', 'boolean']]);
        $filters = $this->validatedFilters($request);
        $user = $request->user();
        $hospital = $user->ensureFacility();

        DB::transaction(function () use ($data, $user, $hospital, $check) {
            HospitalComplianceCheck::query()->updateOrCreate(
                ['hospital_id' => $hospital->id, 'check_key' => $check],
                [
                    'completed_by_user_id' => $data['completed'] ? $user->id : null,
                    'completed_at' => $data['completed'] ? now() : null,
                ]
            );

            ActivityLog::create([
                'hospital_id' => $hospital->id,
                'activity' => $data['completed'] ? 'Compliance check completed' : 'Compliance check reopened',
                'details' => self::CHECKS[$check]['title'],
                'operator' => $user->name,
                'status' => $data['completed'] ? 'completed' : 'coordinating',
            ]);
        });

        return redirect()->route('hospital.reports', $filters)
            ->with('status', $data['completed'] ? 'Compliance checklist updated.' : 'Compliance item reopened.');
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404);
        $hospital = $request->user()->ensureFacility();
        $filters = $this->validatedFilters($request);
        [$start, $end] = $this->period($filters);

        return match ($report) {
            'inventory_snapshot' => $this->exportInventory($hospital->id, $hospital->name, $filters),
            'transfer_log' => $this->exportTransfers($hospital->id, $hospital->name, $start, $end),
            'cold_chain_evidence' => $this->exportColdChain($hospital->id, $hospital->name, $start, $end),
            'credential_checklist' => $this->exportChecklist($hospital, $hospital->name),
            'audit_activity' => $this->exportActivity($hospital->id, $hospital->name, $start, $end),
        };
    }

    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'component' => ['nullable', Rule::in(array_keys(BloodStock::COMPONENTS))],
        ]);
        $to = $data['to'] ?? today()->toDateString();
        $from = $data['from'] ?? today()->subDays(29)->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 365) {
            throw ValidationException::withMessages(['from' => 'Choose a reporting period of one year or less.']);
        }

        return [
            'from' => $from,
            'to' => $to,
            'component' => $data['component'] ?? BloodStock::COMPONENT_WHOLE_BLOOD,
        ];
    }

    private function period(array $filters): array
    {
        return [
            Carbon::parse($filters['from'])->startOfDay(),
            Carbon::parse($filters['to'])->endOfDay(),
        ];
    }

    private function reportCounts(int $hospitalId, array $filters, Carbon $start, Carbon $end): array
    {
        return [
            'inventory_snapshot' => BloodStock::query()
                ->where('hospital_id', $hospitalId)
                ->where('component', $filters['component'])->sum('units'),
            'transfer_log' => Transfer::query()
                ->where(fn (Builder $query) => $query->where('from_hospital_id', $hospitalId)->orWhere('to_hospital_id', $hospitalId))
                ->whereBetween('created_at', [$start, $end])->count(),
            'cold_chain_evidence' => $this->coldChainEvents($hospitalId, $start, $end)->count(),
            'credential_checklist' => HospitalComplianceCheck::query()
                ->where('hospital_id', $hospitalId)->whereNotNull('completed_at')->count(),
            'audit_activity' => ActivityLog::query()->where('hospital_id', $hospitalId)
                ->whereBetween('created_at', [$start, $end])->count()
                + TransferEvent::query()->where('actor_hospital_id', $hospitalId)
                    ->whereBetween('created_at', [$start, $end])->count(),
        ];
    }

    private function coldChainEvents(int $hospitalId, Carbon $start, Carbon $end): Builder
    {
        return TransferEvent::query()
            ->with('transfer')
            ->where('actor_hospital_id', $hospitalId)
            ->whereIn('event_type', ['dispatched', 'received'])
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function activityRows(int $hospitalId, Carbon $start, Carbon $end): Collection
    {
        $activity = ActivityLog::query()->where('hospital_id', $hospitalId)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')->limit(20)->get()
            ->map(fn (ActivityLog $log) => [
                'at' => $log->created_at,
                'activity' => $log->activity,
                'details' => $log->details,
                'operator' => $log->operator ?: 'System',
                'status' => $log->status,
            ]);
        $transfers = TransferEvent::query()->with('transfer')
            ->where('actor_hospital_id', $hospitalId)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')->limit(20)->get()
            ->map(fn (TransferEvent $event) => [
                'at' => $event->created_at,
                'activity' => 'Transfer ' . $event->event_type,
                'details' => ($event->transfer?->reference ? $event->transfer->reference . ' · ' : '') . $event->details,
                'operator' => $event->actor_name ?: 'Transfer workflow',
                'status' => in_array($event->event_type, ['received'], true) ? 'completed' : 'in_transit',
            ]);

        return $activity->concat($transfers)->sortByDesc('at')->take(12)->values();
    }

    private function exportInventory(int $hospitalId, string $hospitalName, array $filters): StreamedResponse
    {
        $stocks = BloodStock::query()->where('hospital_id', $hospitalId)
            ->where('component', $filters['component'])->orderBy('blood_type')->get();

        return $this->csv('bloodlink-stock-snapshot', ['Facility', 'Snapshot date', 'Blood group', 'Component', 'Tracked units', 'Minimum reserve', 'Reserve status'], function ($output) use ($stocks, $hospitalName, $filters) {
            foreach ($stocks as $stock) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $hospitalName, today()->toDateString(), str_replace('-', '−', $stock->blood_type),
                    BloodStock::COMPONENTS[$stock->component] ?? $stock->component,
                    $stock->units, $stock->minimum_reserve, $stock->status(),
                ]));
            }
        });
    }

    private function exportTransfers(int $hospitalId, string $hospitalName, Carbon $start, Carbon $end): StreamedResponse
    {
        $transfers = Transfer::query()
            ->with(['from', 'to'])
            ->where(fn (Builder $query) => $query->where('from_hospital_id', $hospitalId)->orWhere('to_hospital_id', $hospitalId))
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')->get();

        return $this->csv('bloodlink-transfer-log', ['Facility', 'Transfer reference', 'Direction', 'Partner', 'Blood group', 'Component', 'Units', 'Status', 'Created', 'Dispatched', 'Received'], function ($output) use ($transfers, $hospitalId, $hospitalName) {
            foreach ($transfers as $transfer) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $hospitalName, $transfer->reference,
                    $transfer->directionFor($hospitalId),
                    $transfer->counterpartFor($hospitalId)?->name,
                    str_replace('-', '−', $transfer->blood_type),
                    BloodStock::COMPONENTS[$transfer->component] ?? $transfer->component,
                    $transfer->units, $transfer->statusLabelFor($hospitalId),
                    $transfer->created_at?->format('Y-m-d H:i'),
                    $transfer->dispatched_at?->format('Y-m-d H:i'),
                    $transfer->received_at?->format('Y-m-d H:i'),
                ]));
            }
        });
    }

    private function exportColdChain(int $hospitalId, string $hospitalName, Carbon $start, Carbon $end): StreamedResponse
    {
        $events = $this->coldChainEvents($hospitalId, $start, $end)->orderByDesc('created_at')->get();

        return $this->csv('bloodlink-cold-chain-evidence', ['Facility', 'Transfer reference', 'Event', 'Recorded at', 'Actor', 'Temperature °C', 'Event details', 'Checklist evidence'], function ($output) use ($events, $hospitalName) {
            foreach ($events as $event) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $hospitalName, $event->transfer?->reference, $event->event_type,
                    $event->created_at?->format('Y-m-d H:i'), $event->actor_name,
                    $event->temperature, $event->details,
                    $event->checklist ? json_encode($event->checklist, JSON_THROW_ON_ERROR) : '',
                ]));
            }
        });
    }

    private function exportChecklist(Hospital $hospital, string $hospitalName): StreamedResponse
    {
        $checks = HospitalComplianceCheck::query()->where('hospital_id', $hospital->id)->get()->keyBy('check_key');

        return $this->csv('bloodlink-credential-review-checklist', ['Facility', 'Partner verification', 'Checklist item', 'Status', 'Completed at', 'Completed by'], function ($output) use ($hospital, $hospitalName, $checks) {
            foreach (self::CHECKS as $key => $definition) {
                $check = $checks->get($key);
                fputcsv($output, array_map($this->safeCell(...), [
                    $hospitalName, $hospital->is_partner ? 'Verified partner' : 'Pending verification',
                    $definition['title'], $check?->completed_at ? 'Completed' : 'Needs review',
                    $check?->completed_at?->format('Y-m-d H:i'), $check?->completedBy?->name,
                ]));
            }
        });
    }

    private function exportActivity(int $hospitalId, string $hospitalName, Carbon $start, Carbon $end): StreamedResponse
    {
        $activity = ActivityLog::query()->where('hospital_id', $hospitalId)
            ->whereBetween('created_at', [$start, $end])->orderBy('created_at')->get();
        $transferEvents = TransferEvent::query()->with('transfer')
            ->where('actor_hospital_id', $hospitalId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')->get();

        return $this->csv('bloodlink-audit-activity', ['Facility', 'Time', 'Activity', 'Reference / details', 'Operator', 'Status'], function ($output) use ($activity, $transferEvents, $hospitalName) {
            $rows = $activity->map(fn (ActivityLog $log) => [
                'at' => $log->created_at, 'activity' => $log->activity, 'details' => $log->details,
                'operator' => $log->operator, 'status' => $log->status,
            ])->concat($transferEvents->map(fn (TransferEvent $event) => [
                'at' => $event->created_at,
                'activity' => 'Transfer ' . $event->event_type,
                'details' => ($event->transfer?->reference ? $event->transfer->reference . ' · ' : '') . $event->details,
                'operator' => $event->actor_name,
                'status' => $event->event_type === 'received' ? 'completed' : 'in_transit',
            ]))->sortBy('at');

            foreach ($rows as $row) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $hospitalName, $row['at']?->format('Y-m-d H:i:s'), $row['activity'],
                    $row['details'], $row['operator'], $row['status'],
                ]));
            }
        });
    }

    private function csv(string $name, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            $rows($output);
            fclose($output);
        }, $name . '-' . now()->format('Ymd') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
