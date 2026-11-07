<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestDocument extends Model
{
    protected $fillable = ['blood_request_id', 'user_id', 'kind', 'original_name', 'path', 'mime', 'size', 'status'];

    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sizeLabel(): string
    {
        $kb = max(1, (int) round($this->size / 1024));

        return $kb >= 1024 ? number_format($kb / 1024, 1) . ' MB' : $kb . ' KB';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'verified' => 'Verified',
            'rejected' => 'Rejected',
            default    => 'Review required',
        };
    }
}
