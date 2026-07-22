<?php

namespace App\Support\Repair\Models;

use App\Support\Repair\Enums\RepairItemOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SystemRepairItem extends Model
{
    protected $table = 'system_repair_items';

    protected $fillable = [
        'batch_id',
        'repair_key',
        'subject_type',
        'subject_id',
        'subject_key',
        'related_type',
        'related_id',
        'action',
        'category',
        'outcome',
        'skip_reason',
        'error_message',
        'before_snapshot',
        'after_snapshot',
        'attempts',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => RepairItemOutcome::class,
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SystemRepairBatch::class, 'batch_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
