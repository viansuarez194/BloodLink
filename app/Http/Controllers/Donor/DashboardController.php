<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BloodStock;
use App\Models\Hospital;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user     = $request->user();
        $interval = (int) config('bloodlink.donation_interval_days');
        $lowLimit = (int) config('bloodlink.low_stock_units');

        // ----- donations -----
        $totalDonations = $user->completedDonationsCount();
        $lastDonation   = $user->donations()->completed()->orderByDesc('donated_at')->first();
        $recentDonations = $user->donations()->completed()->with('hospital')
            ->orderByDesc('donated_at')->limit(4)->get();

        // ----- eligibility -----
        $nextEligible = $lastDonation?->donated_at?->copy()->addDays($interval);
        $isEligible   = $nextEligible === null || $nextEligible->lte(today());

        // ----- next appointment -----
        $appointment = $user->appointments()->with('hospital')
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        // ----- "who needs my blood type" note, from real stock data -----
        $regionShort = config('bloodlink.region_short')[$user->region] ?? 'your area';
        $partnersInRegion = Hospital::query()->where('is_partner', true)->where('region', $user->region);

        $lowCount = (clone $partnersInRegion)
            ->whereHas('stocks', fn ($q) => $q->where('component', 'whole_blood')->where('blood_type', $user->blood_type)->where('units', '<', $lowLimit))
            ->count();

        $hasStockData = BloodStock::query()
            ->where('component', 'whole_blood')
            ->where('blood_type', $user->blood_type)
            ->whereIn('hospital_id', (clone $partnersInRegion)->select('id'))
            ->exists();

        return view('donor.dashboard', [
            'user'            => $user,
            'tier'            => $user->donorTier(),
            'totalDonations'  => $totalDonations,
            'livesSaved'      => $totalDonations * (int) config('bloodlink.lives_per_donation'),
            'lastDonation'    => $lastDonation,
            'nextEligible'    => $nextEligible,
            'isEligible'      => $isEligible,
            'recentDonations' => $recentDonations,
            'appointment'     => $appointment,
            'regionShort'     => $regionShort,
            'lowCount'        => $lowCount,
            'hasStockData'    => $hasStockData,
        ]);
    }
}
