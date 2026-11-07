<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hospital extends Model
{
    protected $fillable = [
        'name', 'city', 'region', 'is_partner', 'is_synced',
        'address', 'accepts_donations', 'donation_days', 'donation_open', 'donation_close',
        'slot_capacity', 'slot_minutes', 'latitude', 'longitude', 'phone',
    ];

    protected function casts(): array
    {
        return [
            'is_partner' => 'boolean',
            'is_synced'  => 'boolean',
            'accepts_donations' => 'boolean',
        ];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(BloodStock::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public function donorDrives(): HasMany
    {
        return $this->hasMany(DonorDrive::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** Blood requests sent to this facility. */
    public function incomingRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class);
    }

    public function locationLabel(): string
    {
        return $this->address ?: $this->city;
    }

    /** ISO weekday numbers (1 = Monday ... 7 = Sunday) when donations are accepted. */
    public function donationWeekdays(): array
    {
        return collect(explode(',', (string) $this->donation_days))
            ->map(fn ($d) => (int) trim($d))
            ->filter(fn ($d) => $d >= 1 && $d <= 7)
            ->unique()->sort()->values()->all();
    }

    /** "Mon–Sat" or "Mon, Wed, Fri" */
    public function donationDaysLabel(): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days  = $this->donationWeekdays();

        if ($days === []) {
            return 'By arrangement';
        }

        $ranges = [];
        $start  = $prev = $days[0];

        foreach (array_slice($days, 1) as $day) {
            if ($day === $prev + 1) {
                $prev = $day;
                continue;
            }
            $ranges[] = [$start, $prev];
            $start = $prev = $day;
        }
        $ranges[] = [$start, $prev];

        return collect($ranges)->map(function ($r) use ($names) {
            if ($r[0] === $r[1]) return $names[$r[0]];
            return $r[1] - $r[0] === 1 ? $names[$r[0]] . ', ' . $names[$r[1]] : $names[$r[0]] . '–' . $names[$r[1]];
        })->implode(', ');
    }

    /** "8:00 AM–4:00 PM" */
    public function donationHoursLabel(): string
    {
        return \Carbon\Carbon::parse($this->donation_open)->format('g:i A')
            . '–' . \Carbon\Carbon::parse($this->donation_close)->format('g:i A');
    }

    public function units(): HasMany
    {
        return $this->hasMany(BloodUnit::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
