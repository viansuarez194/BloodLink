<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = ['hospital_id', 'activity', 'details', 'operator', 'status'];

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'in_transit' => 'In transit',
            default      => ucfirst($this->status),
        };
    }

    /** Badge colour: green | amber | red | blue | neutral */
    public function badgeKind(): string
    {
        return match ($this->status) {
            'completed'    => 'green',
            'in_transit'   => 'blue',
            'coordinating' => 'amber',
            default        => 'neutral',
        };
    }
}
