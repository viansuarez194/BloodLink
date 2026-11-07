<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchEvent extends Model
{
    protected $fillable = [
        'dispatch_id', 'actor_user_id', 'event_type', 'actor_name',
        'details', 'temperature', 'checklist',
    ];

    protected function casts(): array
    {
        return ['temperature' => 'float', 'checklist' => 'array'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }
}
