<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const PURPOSE_DONATION = 'Whole blood donation';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ATTENDANCE_ATTENDED = 'attended';
    public const ATTENDANCE_NO_SHOW = 'no_show';

    protected $fillable = [
        'user_id', 'hospital_id', 'scheduled_at', 'status',
        'attendance_status', 'attendance_confirmed_by', 'attendance_confirmed_at',
        'purpose', 'ends_at', 'location_detail', 'notes', 'reserved_until', 'confirmed_at',
        'reschedule_requested_at', 'reschedule_requested_for', 'reschedule_note',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at'   => 'datetime',
            'ends_at'        => 'datetime',
            'reserved_until' => 'datetime',
            'confirmed_at'   => 'datetime',
            'attendance_confirmed_at' => 'datetime',
            'reschedule_requested_at' => 'datetime',
            'reschedule_requested_for' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /** What to show under a receiver appointment: confirmed, held for N hours, or expired. */
    public function reservation(): ?array
    {
        if ($this->confirmed_at) {
            return ['state' => 'confirmed', 'text' => 'Appointment confirmed'];
        }

        if (! $this->reserved_until) {
            return null;
        }

        $minutes = (int) ceil(($this->reserved_until->getTimestamp() - now()->getTimestamp()) / 60);

        if ($minutes <= 0) {
            return ['state' => 'expired', 'text' => 'Reservation expired'];
        }

        if ($minutes < 60) {
            return ['state' => 'reserved', 'text' => "Slot reserved for {$minutes} " . ($minutes === 1 ? 'minute' : 'minutes')];
        }

        $hours = (int) ceil($minutes / 60);

        return ['state' => 'reserved', 'text' => "Slot reserved for {$hours} " . ($hours === 1 ? 'hour' : 'hours')];
    }
}
