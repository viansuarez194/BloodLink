<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferEvent extends Model
{
    protected $fillable = [
        'transfer_id', 'actor_hospital_id', 'actor_user_id', 'event_type',
        'actor_name', 'details', 'temperature', 'checklist',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'checklist' => 'array',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function actorHospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'actor_hospital_id');
    }
}
