<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id', 'hospital_id', 'appointment_id', 'center_name', 'donated_at', 'type', 'status',
        'volume_ml', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['donated_at' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function centerLabel(): string
    {
        return $this->hospital?->name ?? $this->center_name ?? 'Unrecorded center';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'whole_blood' => 'Whole Blood',
            'platelets'   => 'Platelets',
            'plasma'      => 'Plasma',
            default       => ucfirst(str_replace('_', ' ', (string) $this->type)),
        };
    }

    /** DON-YYMMDD-NNN, worked out from the date and record number (nothing extra is stored). */
    public function receiptReference(): string
    {
        return 'DON-' . $this->donated_at->format('ymd') . '-' . str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    public function volumeLabel(): string
    {
        return $this->volume_ml ? $this->volume_ml . ' mL · 1 unit' : 'Not recorded';
    }
}
