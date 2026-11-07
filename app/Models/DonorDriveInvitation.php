<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonorDriveInvitation extends Model
{
    public const STATUS_INVITED = 'invited';
    public const STATUS_INTERESTED = 'interested';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_PRELIMINARY_ELIGIBLE = 'preliminary_eligible';
    public const STATUS_NOT_ELIGIBLE = 'not_eligible';

    protected $fillable = [
        'donor_drive_id', 'donor_id', 'invited_by_user_id', 'status', 'sent_at', 'responded_at',
        'appointment_id', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function drive(): BelongsTo
    {
        return $this->belongsTo(DonorDrive::class, 'donor_drive_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
