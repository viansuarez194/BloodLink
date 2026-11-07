<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodStock extends Model
{
    public const COMPONENT_WHOLE_BLOOD = 'whole_blood';
    public const COMPONENT_PACKED_RBC  = 'packed_rbc';
    public const COMPONENT_PLATELETS   = 'platelets';
    public const COMPONENT_PLASMA      = 'plasma';

    public const COMPONENTS = [
        self::COMPONENT_WHOLE_BLOOD => 'Whole Blood',
        self::COMPONENT_PACKED_RBC  => 'Packed RBC',
        self::COMPONENT_PLATELETS   => 'Platelets',
        self::COMPONENT_PLASMA      => 'Plasma',
    ];

    protected $fillable = ['hospital_id', 'blood_type', 'component', 'units', 'minimum_reserve'];

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * healthy  = at or above the minimum reserve
     * low      = at least half of the reserve
     * critical = below half of the reserve
     */
    public function status(): string
    {
        if ($this->units >= $this->minimum_reserve) {
            return 'healthy';
        }

        return $this->units * 2 >= $this->minimum_reserve ? 'low' : 'critical';
    }

    /** Width of the stock bar (0-100). */
    public function fillPercent(): int
    {
        if ($this->minimum_reserve <= 0) {
            return 100;
        }

        return (int) min(100, round($this->units / $this->minimum_reserve * 100));
    }
}
