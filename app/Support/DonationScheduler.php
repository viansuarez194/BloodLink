<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Works out which donation time slots a hospital offers and how many are still free.
 */
class DonationScheduler
{
    public function __construct(private Hospital $hospital)
    {
    }

    public function isOpenOn(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), $this->hospital->donationWeekdays(), true);
    }

    /** Start time of every slot on this date (before taking bookings into account). */
    public function slotStarts(CarbonInterface $date): array
    {
        if (! $this->isOpenOn($date)) {
            return [];
        }

        $day     = $date->toDateString();
        $minutes = max(15, (int) $this->hospital->slot_minutes);
        $open    = Carbon::parse($day . ' ' . $this->hospital->donation_open);
        $close   = Carbon::parse($day . ' ' . $this->hospital->donation_close);

        [$breakFrom, $breakTo] = config('bloodlink.booking.break', ['12:00', '13:00']);
        $breakStart = Carbon::parse($day . ' ' . $breakFrom);
        $breakEnd   = Carbon::parse($day . ' ' . $breakTo);

        $slots = [];
        $start = $open->copy();

        while ($start->copy()->addMinutes($minutes)->lte($close)) {
            $end = $start->copy()->addMinutes($minutes);

            // Skip any slot that touches the lunch break.
            if (! ($start->lt($breakEnd) && $end->gt($breakStart))) {
                $slots[] = $start->copy();
            }

            $start->addMinutes($minutes);
        }

        return $slots;
    }

    public function slotEnd(CarbonInterface $start): Carbon
    {
        return Carbon::instance($start)->addMinutes(max(15, (int) $this->hospital->slot_minutes));
    }

    /** "Y-m-d H:i" => number of donors already booked, for the given period. */
    public function bookedCounts(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Appointment::query()
            ->where('hospital_id', $this->hospital->id)
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->whereBetween('scheduled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereHas('user', fn ($q) => $q->where('role', User::ROLE_DONOR))
            ->get(['scheduled_at'])
            ->countBy(fn ($appointment) => $appointment->scheduled_at->format('Y-m-d H:i'));
    }

    public function remaining(CarbonInterface $slotStart, Collection $booked): int
    {
        $taken = (int) $booked->get($slotStart->format('Y-m-d H:i'), 0);

        return max(0, (int) $this->hospital->slot_capacity - $taken);
    }
}
