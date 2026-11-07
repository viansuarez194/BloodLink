<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\Dispatch;
use App\Models\DonorDrive;
use App\Models\Hospital;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();            // creates an unverified facility if the account has none yet
        $interval = (int) config('bloodlink.donation_interval_days');

        $regionShort = config('bloodlink.region_short')[$hospital->region] ?? 'your area';
        $typeKeys    = array_keys(config('bloodlink.blood_types'));

        // ----- which component tab is open -----
        $component = array_key_exists((string) $request->query('component'), BloodStock::COMPONENTS)
            ? $request->query('component')
            : BloodStock::COMPONENT_WHOLE_BLOOD;

        // ----- inventory -----
        $stocks     = $hospital->stocks()->get();                                   // every component
        $wholeBlood = $stocks->where('component', BloodStock::COMPONENT_WHOLE_BLOOD)->keyBy('blood_type');
        $shown      = $stocks->where('component', $component)->keyBy('blood_type');

        $grid = collect($typeKeys)->map(fn ($type) => [
            'type'  => $type,
            'stock' => $shown->get($type),           // null = not tracked yet
        ]);

        // Low / critical types are judged on whole blood.
        $lowTypes      = collect($typeKeys)->filter(fn ($t) => $wholeBlood->has($t) && $wholeBlood[$t]->status() !== 'healthy')->values();
        $criticalTypes = $lowTypes->filter(fn ($t) => $wholeBlood[$t]->status() === 'critical')->values();

        $lastUpdated = $stocks->max('updated_at');

        // ----- another partner that could send units for the low types -----
        $transferHint = null;
        if ($lowTypes->isNotEmpty()) {
            $best = BloodStock::query()
                ->selectRaw('hospital_id, SUM(units) AS total')
                ->where('component', BloodStock::COMPONENT_WHOLE_BLOOD)
                ->whereIn('blood_type', $lowTypes->all())
                ->where('hospital_id', '!=', $hospital->id)
                ->whereIn('hospital_id', Hospital::query()->where('is_partner', true)->where('region', $hospital->region)->select('id'))
                ->groupBy('hospital_id')
                ->orderByDesc('total')
                ->first();

            if ($best && (int) $best->total > 0) {
                $transferHint = ['hospital' => Hospital::find($best->hospital_id), 'units' => (int) $best->total];
            }
        }

        // ----- incoming requests that still need a decision -----
        $incoming = BloodRequest::query()
            ->where('hospital_id', $hospital->id)
            ->whereIn('status', [BloodRequest::STATUS_PENDING, BloodRequest::STATUS_VERIFIED])
            ->with('user')
            ->get()
            ->sort(fn ($a, $b) => [$a->priorityRank(), $a->needed_by?->getTimestamp() ?? PHP_INT_MAX]
                              <=> [$b->priorityRank(), $b->needed_by?->getTimestamp() ?? PHP_INT_MAX])
            ->values();

        $priorityCounts = [
            'critical' => $incoming->filter(fn ($r) => $r->priority() === 'critical')->count(),
            'urgent'   => $incoming->filter(fn ($r) => $r->priority() === 'urgent')->count(),
            'routine'  => $incoming->filter(fn ($r) => $r->priority() === 'routine')->count(),
        ];

        // ----- dispatches -----
        $activeRuns = Dispatch::query()
            ->where('hospital_id', $hospital->id)
            ->whereIn('status', Dispatch::ACTIVE_STATUSES)
            ->with('destination')
            ->orderBy('eta_at')
            ->get();

        $completedToday = Dispatch::query()
            ->where('hospital_id', $hospital->id)
            ->where('status', Dispatch::STATUS_DELIVERED)
            ->whereDate('delivered_at', today())
            ->get();

        $dispatchedToday = Dispatch::query()
            ->where('hospital_id', $hospital->id)
            ->whereDate('dispatched_at', today())
            ->count();

        $onTimePercent = $completedToday->isEmpty() ? null : (int) round(
            $completedToday->filter(fn ($d) => $d->eta_at && $d->delivered_at && $d->delivered_at->lte($d->eta_at))->count()
            / $completedToday->count() * 100
        );

        // ----- donors -----
        $drive = DonorDrive::query()
            ->where('hospital_id', $hospital->id)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        $donorTarget = $criticalTypes->first() ?? $lowTypes->first();
        $eligibleDonors = $donorTarget ? User::query()
            ->where('role', User::ROLE_DONOR)
            ->where('region', $hospital->region)
            ->where('blood_type', $donorTarget)
            ->whereDoesntHave('donations', fn ($q) => $q->completed()->where('donated_at', '>', today()->subDays($interval)))
            ->count() : 0;

        // ----- transfers + audit log -----
        $transfers = Transfer::query()
            ->where(fn ($q) => $q->where('from_hospital_id', $hospital->id)->orWhere('to_hospital_id', $hospital->id))
            ->with(['from', 'to'])
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $activity = ActivityLog::query()->where('hospital_id', $hospital->id)->orderByDesc('created_at')->limit(5)->get();

        return view('hospital.dashboard', [
            'user'           => $user,
            'hospital'       => $hospital,
            'regionShort'    => $regionShort,
            'component'      => $component,
            'components'     => BloodStock::COMPONENTS,
            'grid'           => $grid,
            'totalUnits'     => (int) $stocks->sum('units'),
            'bloodGroups'    => $stocks->pluck('blood_type')->unique()->count(),
            'lastUpdated'    => $lastUpdated,
            'lowTypes'       => $lowTypes,
            'criticalTypes'  => $criticalTypes,
            'transferHint'   => $transferHint,
            'incoming'       => $incoming,
            'priorityCounts' => $priorityCounts,
            'actionCount'    => $priorityCounts['critical'] + $priorityCounts['urgent'],
            'activeRuns'     => $activeRuns,
            'completedToday' => $completedToday->count(),
            'dispatchedToday'=> $dispatchedToday,
            'onTimePercent'  => $onTimePercent,
            'drive'          => $drive,
            'donorTarget'    => $donorTarget,
            'eligibleDonors' => $eligibleDonors,
            'transfers'      => $transfers,
            'activity'       => $activity,
        ]);
    }
}
