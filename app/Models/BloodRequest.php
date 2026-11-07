<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BloodRequest extends Model
{
    public const STATUS_DRAFT     = 'draft';      // saved by the receiver, not submitted yet
    public const STATUS_PENDING   = 'pending';
    public const STATUS_VERIFIED  = 'verified';
    public const STATUS_MATCHED   = 'matched';
    public const STATUS_READY     = 'ready';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Requests that are still being worked on. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_MATCHED,
        self::STATUS_READY,
        self::STATUS_IN_TRANSIT,
        self::STATUS_PAUSED,
    ];

    protected $fillable = [
        'reference', 'user_id', 'patient_name', 'blood_type', 'component', 'units',
        'clinical_note', 'is_urgent', 'needed_by', 'hospital_id', 'status',
        'verified_at', 'matched_at', 'ready_at', 'in_transit_at', 'completed_at', 'cancelled_at',
        'urgency', 'relationship', 'patient_reference', 'contact_number', 'physician_name',
        'ward_service', 'consented_at', 'submitted_at', 'share_authorized_data',
        'consent_revoked_at',
        'review_checks', 'clarification_note', 'clarification_at', 'escalated_at',
        'pickup_instructions', 'pickup_recipient_name', 'pickup_recipient_relationship',
        'pickup_completed_by', 'pickup_handoff_checks',
    ];

    protected function casts(): array
    {
        return [
            'is_urgent'    => 'boolean',
            'units'        => 'integer',
            'needed_by'    => 'datetime',
            'verified_at'  => 'datetime',
            'matched_at'   => 'datetime',
            'ready_at'     => 'datetime',
            'in_transit_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'consented_at' => 'datetime',
            'consent_revoked_at' => 'datetime',
            'submitted_at' => 'datetime',
            'share_authorized_data' => 'boolean',
            'review_checks' => 'array',
            'clarification_at' => 'datetime',
            'escalated_at' => 'datetime',
            'pickup_handoff_checks' => 'array',
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

    public function documents(): HasMany
    {
        return $this->hasMany(RequestDocument::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /** REQ-YYMMDD-NNNN, unique. Use this when we build the "New blood request" page. */
    public static function generateReference(): string
    {
        do {
            $reference = 'REQ-' . now()->format('ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    /** "O-" shown with a proper minus sign, like in the design. */
    public function bloodTypeLabel(): string
    {
        return str_replace('-', '−', $this->blood_type);
    }

    public function componentLabel(): string
    {
        return match ($this->component) {
            'whole_blood' => 'whole blood',
            'packed_rbc'  => 'packed RBC',
            default       => str_replace('_', ' ', (string) $this->component),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PAUSED => 'Paused · consent withdrawn',
            self::STATUS_IN_TRANSIT => 'In transit',
            default => ucfirst($this->status),
        };
    }

    /** Draft, pending, paused, and cancelled requests show a neutral badge. */
    public function statusIsNeutral(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_PAUSED, self::STATUS_CANCELLED], true);
    }

    /**
     * Priority for the hospital queue:
     * critical = urgent and needed within 3 hours (or overdue)
     * urgent   = marked urgent
     * routine  = everything else
     */
    public function priority(): string
    {
        if (in_array($this->urgency, ['critical', 'urgent', 'routine'], true)) {
            return $this->urgency;
        }

        if (! $this->is_urgent) {
            return 'routine';
        }

        return $this->needed_by && $this->needed_by->lte(now()->addHours(3)) ? 'critical' : 'urgent';
    }

    public function priorityRank(): int
    {
        return ['critical' => 0, 'urgent' => 1, 'routine' => 2][$this->priority()];
    }

    /** "Within 2 hrs", "By 4:30 PM", "By Oct 3", "Overdue" */
    public function needByLabel(): string
    {
        if (! $this->needed_by) {
            return 'No deadline';
        }

        if ($this->needed_by->isPast()) {
            return 'Overdue';
        }

        $hours = (int) ceil(($this->needed_by->getTimestamp() - now()->getTimestamp()) / 3600);

        if ($hours <= 3) {
            return "Within {$hours} " . Str::plural('hr', $hours);
        }

        return 'By ' . ($this->needed_by->isToday() ? $this->needed_by->format('g:i A') : $this->needed_by->format('M j'));
    }

    /** "B− · 2 whole-blood units" */
    public function requirementText(): string
    {
        $word = [
            'whole_blood' => 'whole-blood',
            'packed_rbc'  => 'packed RBC',
            'platelets'   => 'platelet',
            'plasma'      => 'plasma',
        ][$this->component] ?? $this->componentLabel();

        return $this->bloodTypeLabel() . ' · ' . $this->units . ' ' . $word . ' ' . Str::plural('unit', $this->units);
    }

    /** "Within 2 hrs · 12:46 PM", "By 1:00 PM", "Sep 28 · 8:00 AM" */
    public function deadlineLabel(): string
    {
        if (! $this->needed_by) {
            return 'No deadline';
        }

        if ($this->needed_by->isPast()) {
            return 'Overdue · ' . $this->needed_by->format('M j · g:i A');
        }

        $hours = (int) ceil(($this->needed_by->getTimestamp() - now()->getTimestamp()) / 3600);

        if ($hours <= 3) {
            return "Within {$hours} " . Str::plural('hr', $hours) . ' · ' . $this->needed_by->format('g:i A');
        }

        return $this->needed_by->isToday()
            ? 'By ' . $this->needed_by->format('g:i A')
            : $this->needed_by->format('M j · g:i A');
    }

    public function reviewCheck(string $key): bool
    {
        return (bool) ($this->review_checks[$key] ?? false);
    }

    /** The four progress steps shown on the dashboard. */
    public function steps(): array
    {
        return [
            ['label' => 'Request filed',     'at' => $this->created_at],
            ['label' => 'Verified',          'at' => $this->verified_at],
            ['label' => 'Facility matched',  'at' => $this->matched_at],
            ['label' => 'Pickup ready',      'at' => $this->ready_at],
        ];
    }
}
