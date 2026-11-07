<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispatch extends Model
{
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_EN_ROUTE  = 'en_route';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    /** Runs that are still on the board. */
    public const ACTIVE_STATUSES = [self::STATUS_PREPARING, self::STATUS_EN_ROUTE];

    protected $fillable = [
        'hospital_id', 'blood_request_id', 'destination_hospital_id', 'destination_name',
        'blood_type', 'component', 'units', 'vehicle', 'team', 'status',
        'eta_at', 'dispatched_at', 'delivered_at', 'cold_chain_temp',
    ];

    protected function casts(): array
    {
        return [
            'eta_at'          => 'datetime',
            'dispatched_at'   => 'datetime',
            'delivered_at'    => 'datetime',
            'cold_chain_temp' => 'float',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'destination_hospital_id');
    }

    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class);
    }

    public function manifestUnits(): BelongsToMany
    {
        return $this->belongsToMany(BloodUnit::class, 'dispatch_unit_manifest')->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DispatchEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function destinationLabel(): string
    {
        return $this->destination?->name ?? $this->destination_name ?? 'destination';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_EN_ROUTE  => 'En route',
            self::STATUS_PREPARING => 'Preparing',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_CANCELLED => 'Cancelled',
            default                => ucfirst($this->status),
        };
    }

    /** Badge colour: green | amber | red | blue | neutral */
    public function badgeKind(): string
    {
        return match ($this->status) {
            self::STATUS_EN_ROUTE  => 'blue',
            self::STATUS_PREPARING => 'amber',
            self::STATUS_DELIVERED => 'green',
            default                => 'neutral',
        };
    }
}
