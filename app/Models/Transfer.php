<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transfer extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $fillable = [
        'reference', 'from_hospital_id', 'to_hospital_id', 'blood_type', 'component',
        'units', 'status', 'eta_at', 'delivered_at', 'requested_by_hospital_id',
        'requested_by_user_id', 'reason', 'dispatched_at', 'received_at', 'receiving_staff',
    ];

    protected function casts(): array
    {
        return [
            'eta_at' => 'datetime',
            'delivered_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'units' => 'integer',
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'from_hospital_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'to_hospital_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'requested_by_hospital_id');
    }

    public function unitsOnTransfer(): BelongsToMany
    {
        return $this->belongsToMany(BloodUnit::class, 'transfer_units')->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransferEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** TRF-YYMMDD-NNNN, unique. */
    public static function generateReference(): string
    {
        do {
            $reference = 'TRF-' . now()->format('ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    /** "inbound" if the given facility receives the blood, otherwise "outbound". */
    public function directionFor(int $hospitalId): string
    {
        return $this->to_hospital_id === $hospitalId ? 'inbound' : 'outbound';
    }

    public function counterpartFor(int $hospitalId): ?Hospital
    {
        return $this->directionFor($hospitalId) === 'inbound' ? $this->from : $this->to;
    }

    public function statusLabelFor(int $hospitalId): string
    {
        return match ($this->status) {
            self::STATUS_IN_TRANSIT => $this->directionFor($hospitalId) === 'inbound' ? 'In transit' : 'En route',
            self::STATUS_PENDING    => 'Pending',
            self::STATUS_COMPLETED  => 'Completed',
            default                 => ucfirst($this->status),
        };
    }

    public function badgeKind(): string
    {
        return match ($this->status) {
            self::STATUS_IN_TRANSIT => 'blue',
            self::STATUS_PENDING    => 'amber',
            self::STATUS_COMPLETED  => 'green',
            default                 => 'neutral',
        };
    }
}
