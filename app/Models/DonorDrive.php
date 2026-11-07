<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DonorDrive extends Model
{
    protected $fillable = [
        'hospital_id', 'name', 'team', 'scheduled_at', 'verified_donors',
        'target_blood_type', 'venue', 'coordinator_name', 'recruitment_target',
        'consent_reviewed', 'supplies_ready', 'screening_staff_ready',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'consent_reviewed' => 'boolean',
            'supplies_ready' => 'boolean',
            'screening_staff_ready' => 'boolean',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(DonorDriveInvitation::class);
    }
}
