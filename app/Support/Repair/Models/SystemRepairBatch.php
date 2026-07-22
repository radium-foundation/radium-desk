<?php

namespace App\Support\Repair\Models;

use App\Support\Repair\Enums\RepairBatchStatus;
use App\Support\Repair\Enums\RepairPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemRepairBatch extends Model
{
    protected $table = 'system_repair_batches';

    protected $fillable = [
        'uuid',
        'repair_key',
        'status',
        'phase',
        'options',
        'environment',
        'initiated_by',
        'started_at',
        'completed_at',
        'checkpoint',
        'counts',
        'report_paths',
        'parent_batch_uuid',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'status' => RepairBatchStatus::class,
            'phase' => RepairPhase::class,
            'options' => 'array',
            'checkpoint' => 'array',
            'counts' => 'array',
            'report_paths' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SystemRepairItem::class, 'batch_id');
    }
}
