<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user        = $request->user();
        $regionShort = config('bloodlink.region_short')[$user->region] ?? 'your area';

        // ----- the request being worked on right now -----
        $activeRequest = $user->bloodRequests()->active()->with('hospital')
            ->orderByDesc('created_at')->first();

        $bloodType = $activeRequest?->blood_type ?? $user->blood_type;

        // ----- metrics -----
        $activeCount       = $user->activeRequestsCount();
        $matchedInProgress = $user->bloodRequests()
            ->whereIn('status', [
                BloodRequest::STATUS_PENDING,
                BloodRequest::STATUS_VERIFIED,
                BloodRequest::STATUS_MATCHED,
                BloodRequest::STATUS_READY,
                BloodRequest::STATUS_IN_TRANSIT,
            ])
            ->whereNotNull('hospital_id')
            ->count();
        $matchedFacilities = $user->bloodRequests()
            ->whereNotNull('hospital_id')
            ->whereNotIn('status', [BloodRequest::STATUS_CANCELLED, BloodRequest::STATUS_DRAFT])
            ->distinct()->count('hospital_id');

        $myAvg      = $this->averageMatchMinutes($user->id);
        $networkAvg = $this->averageMatchMinutes($user->id, excludeUser: true);

        $savedFacilities = $user->savedFacilities()->get();
        $savedWithStock  = $savedFacilities->isEmpty() ? 0 : BloodStock::query()
            ->where('component', 'whole_blood')
            ->where('blood_type', $bloodType)
            ->where('units', '>', 0)
            ->whereIn('hospital_id', $savedFacilities->pluck('id'))
            ->distinct()->count('hospital_id');

        // ----- upcoming appointment + how long the slot is held -----
        $appointment = $user->appointments()->with('hospital')
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        // ----- matched + saved facilities (max 3 cards) -----
        $facilities = collect();
        if ($activeRequest?->hospital && $activeRequest->status !== BloodRequest::STATUS_PAUSED) {
            $facilities->push($activeRequest->hospital);
        }
        foreach ($savedFacilities as $hospital) {
            if ($facilities->doesntContain('id', $hospital->id)) {
                $facilities->push($hospital);
            }
        }
        $facilities = $facilities->take(3);

        $stockByHospital = $facilities->isEmpty() ? collect() : BloodStock::query()
            ->where('component', 'whole_blood')
            ->where('blood_type', $bloodType)
            ->whereIn('hospital_id', $facilities->pluck('id'))
            ->pluck('units', 'hospital_id');

        return view('receiver.dashboard', [
            'user'              => $user,
            'regionShort'       => $regionShort,
            'bloodType'         => $bloodType,
            'greeting'          => $this->greeting(),
            'activeRequest'     => $activeRequest,
            'bannerMessage'     => $this->bannerMessage($activeRequest, $bloodType),
            'activeCount'       => $activeCount,
            'matchedInProgress' => $matchedInProgress,
            'matchedFacilities' => $matchedFacilities,
            'avgLabel'          => $this->minutesLabel($myAvg),
            'avgCompare'        => $this->compareText($myAvg, $networkAvg),
            'savedCount'        => $savedFacilities->count(),
            'savedWithStock'    => $savedWithStock,
            'appointment'       => $appointment,
            'reservation'       => $this->reservation($appointment),
            'facilities'        => $facilities,
            'savedIds'          => $savedFacilities->pluck('id')->all(),
            'stockByHospital'   => $stockByHospital,
            'recentRequests'    => $user->bloodRequests()->with('hospital')->where('status', '!=', BloodRequest::STATUS_DRAFT)->orderByDesc('created_at')->limit(4)->get(),
        ]);
    }

    private function greeting(): string
    {
        $hour = now()->hour;

        return $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    }

    private function bannerMessage(?BloodRequest $request, ?string $bloodType): string
    {
        if (! $request) {
            return 'You have no active blood requests. Search nearby partner stocks, or file a request if a patient needs blood.';
        }

        $type     = $request->bloodTypeLabel();
        $hospital = $request->hospital?->name;

        return match ($request->status) {
            BloodRequest::STATUS_PAUSED    => 'Coordination is paused because information-sharing consent was withdrawn. Restore consent in your Medical Profile to return the request to facility review.',
            BloodRequest::STATUS_PENDING  => "Your {$type} request was filed. BloodLink is reviewing the details now.",
            BloodRequest::STATUS_VERIFIED => "Your {$type} request is verified. BloodLink is looking for a facility with available units.",
            BloodRequest::STATUS_MATCHED  => "Your " . ($request->is_urgent ? 'urgent ' : '') . "{$type} request has been matched with " . ($hospital ?? 'a partner facility') . ". BloodLink is coordinating final verification now.",
            BloodRequest::STATUS_READY    => "Your {$type} blood is authorized and ready for pickup at " . ($hospital ?? 'the matched facility') . ". Review the facility's pickup instructions before traveling.",
            BloodRequest::STATUS_IN_TRANSIT => "Your {$type} request has been released to transport by " . ($hospital ?? 'the matched facility') . ". Contact the facility for delivery updates.",
            default                       => "BloodLink is working on your {$type} request.",
        };
    }

    /** Average minutes between "Request filed" and "Facility matched". */
    private function averageMatchMinutes(int $userId, bool $excludeUser = false): ?float
    {
        $query = DB::table('blood_requests')->whereNotNull('matched_at');

        $excludeUser ? $query->where('user_id', '!=', $userId) : $query->where('user_id', $userId);

        // PostgreSQL syntax (this project uses PostgreSQL).
        $minutes = $query
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (matched_at - created_at))) / 60 AS minutes')
            ->value('minutes');

        return $minutes === null ? null : (float) $minutes;
    }

    private function minutesLabel(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $minutes = (int) round($minutes);

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        return intdiv($minutes, 60) . ' h' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '');
    }

    private function compareText(?float $mine, ?float $network): string
    {
        if ($mine === null) {
            return 'No matched requests yet';
        }

        if ($network === null) {
            return 'Based on your matched requests';
        }

        $diff = (int) round($network - $mine);

        if ($diff === 0) {
            return 'About the same as network avg.';
        }

        $word = abs($diff) === 1 ? 'minute' : 'minutes';

        return abs($diff) . " {$word} " . ($diff > 0 ? 'faster' : 'slower') . ' than network avg.';
    }

    /** What to show under the appointment: confirmed, held for N hours, or expired. */
    private function reservation(?Appointment $appointment): ?array
    {
        if (! $appointment) {
            return null;
        }

        if ($appointment->confirmed_at) {
            return ['state' => 'confirmed', 'text' => 'Appointment confirmed'];
        }

        if (! $appointment->reserved_until) {
            return null;
        }

        $minutes = (int) ceil(($appointment->reserved_until->getTimestamp() - now()->getTimestamp()) / 60);

        if ($minutes <= 0) {
            return ['state' => 'expired', 'text' => 'Reservation expired'];
        }

        if ($minutes < 60) {
            return ['state' => 'reserved', 'text' => "Slot reserved for {$minutes} " . Str::plural('minute', $minutes)];
        }

        $hours = (int) ceil($minutes / 60);

        return ['state' => 'reserved', 'text' => "Slot reserved for {$hours} " . Str::plural('hour', $hours)];
    }
}
