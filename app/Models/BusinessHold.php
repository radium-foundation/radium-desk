<?php

namespace App\Models;

use App\Enums\BusinessHoldType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BusinessHold extends Model
{
    protected $fillable = [
        'incident_id',
        'hold_type',
        'source_type',
        'source_id',
        'activated_at',
        'cleared_at',
        'activated_by',
        'cleared_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'hold_type' => BusinessHoldType::class,
            'activated_at' => 'datetime',
            'cleared_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cleared_at');
    }

    public function isActive(): bool
    {
        return $this->cleared_at === null;
    }
}
