<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\User;
use App\Notifications\BloodLinkNotification;
use App\Support\DonationScheduler;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function show(Request $request)
    {
        $user   = $request->user();
        $status = $user->donationStatus();

        [$earliest, $horizon, $minStart] = $this->window($status['next']);

        // ----- donation centers (the donor's own region first) -----
        $hospitals = Hospital::query()
            ->where('is_partner', true)
            ->where('accepts_donations', true)
            ->orderBy('name')
            ->get()
            ->sortBy(fn ($h) => $h->region === $user->region ? 0 : 1)
            ->values();

        $existing = $user->appointments()->with('hospital')
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        $base = [
            'user'        => $user,
            'status'      => $status,
            'isEligible'  => $status['eligible'],
            'earliest'    => $earliest,
            'hospitals'   => $hospitals,
            'hospital'    => null,
            'existing'    => $existing,
        ];

        if ($hospitals->isEmpty()) {
            return view('donor.schedule', $base);
        }

        $hospital  = $hospitals->firstWhere('id', (int) $request->query('hospital')) ?? $hospitals->first();
        $scheduler = new DonationScheduler($hospital);

        // ----- which month is shown -----
        $dateParam = $this->parseDate($request->query('date'));
        $month     = $this->parseMonth($request->query('month')) ?? ($dateParam?->copy()->startOfMonth());
        $minMonth  = $earliest->copy()->startOfMonth();
        $maxMonth  = $horizon->copy()->startOfMonth();
        $month     = $month ?? $minMonth->copy();
        $month     = $month->lt($minMonth) ? $minMonth->copy() : ($month->gt($maxMonth) ? $maxMonth->copy() : $month);

        $gridStart = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $gridEnd   = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $booked    = $scheduler->bookedCounts($gridStart, $gridEnd);

        // ----- how many bookable slots each day of this month has -----
        $openSlots = [];
        for ($day = $month->copy()->startOfMonth(); $day->month === $month->month; $day->addDay()) {
            $openSlots[$day->toDateString()] = ($day->gte($earliest) && $day->lte($horizon))
                ? $this->bookableSlots($scheduler, $day, $booked, $minStart)->count()
                : 0;
        }

        // ----- selected date (the first open day if none was chosen) -----
        $selectedDate = null;
        if ($dateParam && ($openSlots[$dateParam->toDateString()] ?? 0) > 0) {
            $selectedDate = $dateParam;
        } else {
            $first = collect($openSlots)->filter(fn ($n) => $n > 0)->keys()->first();
            $selectedDate = $first ? Carbon::parse($first) : null;
        }

        // ----- calendar cells -----
        $weeks = [];
        $cellIndex = 0;
        for ($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay(), $cellIndex++) {
            $inMonth = $day->month === $month->month;
            $key     = $day->toDateString();
            $open    = $inMonth && ($openSlots[$key] ?? 0) > 0;

            $weeks[intdiv($cellIndex, 7)][] = [
                'label'    => $inMonth ? $day->day : '',
                'inMonth'  => $inMonth,
                'open'     => $open,
                'selected' => $open && $selectedDate && $key === $selectedDate->toDateString(),
                'url'      => $open ? route('donor.schedule', ['hospital' => $hospital->id, 'month' => $month->format('Y-m'), 'date' => $key]) : null,
            ];
        }

        // ----- slots for the selected date -----
        $slots        = collect();
        $selectedTime = null;
        if ($selectedDate) {
            $slots = collect($scheduler->slotStarts($selectedDate))->map(fn ($start) => [
                'time'      => $start->format('H:i'),
                'label'     => $start->format('g:i A'),
                'remaining' => $scheduler->remaining($start, $booked),
                'bookable'  => $start->gte($minStart) && $scheduler->remaining($start, $booked) > 0,
                'start'     => $start,
            ]);

            $selectedTime = $slots->first(fn ($s) => $s['bookable'] && $s['time'] === $request->query('time'));
        }

        $nav = fn (Carbon $target) => route('donor.schedule', ['hospital' => $hospital->id, 'month' => $target->format('Y-m')]);

        return view('donor.schedule', $base + [
            'hospital'     => $hospital,
            'otherCenters' => $hospitals->where('id', '!=', $hospital->id)->take(3)->pluck('name'),
            'month'        => $month,
            'prevUrl'      => $month->gt($minMonth) ? $nav($month->copy()->subMonth()) : null,
            'nextUrl'      => $month->lt($maxMonth) ? $nav($month->copy()->addMonth()) : null,
            'weeks'        => array_values($weeks),
            'selectedDate' => $selectedDate,
            'slots'        => $slots,
            'selectedTime' => $selectedTime,
            'slotEnd'      => $selectedTime ? $scheduler->slotEnd($selectedTime['start']) : null,
            'timeUrl'      => fn ($time) => route('donor.schedule', [
                'hospital' => $hospital->id,
                'month'    => $month->format('Y-m'),
                'date'     => $selectedDate?->toDateString(),
                'time'     => $time,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'hospital' => ['required', 'integer'],
            'date'     => ['required', 'date_format:Y-m-d'],
            'time'     => ['required', 'date_format:H:i'],
        ], [
            'date.required' => 'Please choose a date.',
            'time.required' => 'Please choose a time slot.',
        ]);

        $status = $user->donationStatus();
        [$earliest, $horizon, $minStart] = $this->window($status['next']);

        $appointment = DB::transaction(function () use ($user, $data, $earliest, $horizon, $minStart) {
            // Lock the hospital so two donors cannot take the last slot at the same time.
            $hospital = Hospital::query()
                ->whereKey($data['hospital'])
                ->where('is_partner', true)
                ->where('accepts_donations', true)
                ->lockForUpdate()
                ->first();

            if (! $hospital) {
                throw ValidationException::withMessages(['hospital' => 'That donation center is not available.']);
            }

            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $date = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();

            if ($date->lt($earliest) || $date->gt($horizon)) {
                throw ValidationException::withMessages(['date' => 'That date is not available for you.']);
            }

            $scheduler = new DonationScheduler($hospital);
            $slot = collect($scheduler->slotStarts($date))->first(fn ($s) => $s->format('H:i') === $data['time']);

            if (! $slot || $slot->lt($minStart)) {
                throw ValidationException::withMessages(['time' => 'That time slot is no longer available.']);
            }

            $booked = $scheduler->bookedCounts($date, $date);

            if ($scheduler->remaining($slot, $booked) < 1) {
                throw ValidationException::withMessages(['time' => 'Sorry, that slot was just taken. Please pick another time.']);
            }

            // One upcoming appointment at a time: a new booking replaces the old one.
            $lockedUser->appointments()
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->where('scheduled_at', '>=', now())
                ->update(['status' => Appointment::STATUS_CANCELLED]);

            return $lockedUser->appointments()->create([
                'hospital_id'     => $hospital->id,
                'scheduled_at'    => $slot,
                'ends_at'         => $scheduler->slotEnd($slot),
                'status'          => Appointment::STATUS_SCHEDULED,
                'purpose'         => Appointment::PURPOSE_DONATION,
                'location_detail' => $hospital->name,
                'notes'           => 'Bring a valid photo ID and your medication list.',
            ]);
        });

        $appointment->load('hospital');
        $appointment->hospital?->accounts()->each(fn ($account) => $account->notify(new BloodLinkNotification(
            'New donor appointment',
            'A donor booked an appointment for ' . $appointment->scheduled_at->format('M j, Y · g:i A') . '.',
            '/hospital/donors',
            'appointment'
        )));
        $user->notify(new BloodLinkNotification(
            'Donation appointment booked',
            'Your appointment at ' . ($appointment->hospital?->name ?? 'the donation center') . ' is scheduled for ' . $appointment->scheduled_at->format('M j, Y · g:i A') . '.',
            '/donor/dashboard',
            'appointment'
        ));

        return redirect()->route('donor.dashboard')->with(
            'status',
            'Your appointment is booked for ' . $appointment->scheduled_at->format('F j, Y \a\t g:i A') . '. Booking is not clearance to donate; the hospital confirms eligibility on the day.'
        );
    }

    /** [earliest bookable day, last bookable day, earliest slot start time] */
    private function window($nextEligible): array
    {
        $today    = today();
        $earliest = ($nextEligible && $nextEligible->gt($today)) ? $nextEligible->copy()->startOfDay() : $today->copy();
        $horizon  = $today->copy()->addDays((int) config('bloodlink.booking.horizon_days'));

        if ($earliest->gt($horizon)) {
            $horizon = $earliest->copy()->addDays(30);
        }

        return [$earliest, $horizon, now()->addHours((int) config('bloodlink.booking.lead_hours'))];
    }

    private function bookableSlots(DonationScheduler $scheduler, Carbon $day, $booked, Carbon $minStart)
    {
        return collect($scheduler->slotStarts($day))
            ->filter(fn ($start) => $start->gte($minStart) && $scheduler->remaining($start, $booked) > 0);
    }

    private function parseDate($value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseMonth($value): ?Carbon
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)
            ? $this->parseDate($value . '-01')
            : null;
    }
}
