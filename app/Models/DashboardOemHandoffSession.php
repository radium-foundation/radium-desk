<?php

namespace App\Models;

use App\Enums\DashboardOemHandoffSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardOemHandoffSession extends Model
{
    protected $fillable = [
        'public_id',
        'incident_id',
        'user_id',
        'oem',
        'device_model',
        'serial_number',
        'handoff_token_hash',
        'status',
        'expires_at',
        'draft_submitted_at',
        'draft_reviewed_at',
        'draft_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => DashboardOemHandoffSessionStatus::class,
            'expires_at' => 'datetime',
            'draft_submitted_at' => 'datetime',
            'draft_reviewed_at' => 'datetime',
            'draft_payload' => 'array',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
