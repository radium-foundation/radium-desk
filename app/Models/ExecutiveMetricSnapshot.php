<?php

namespace App\Models;

use App\Enums\ExecutiveSnapshotGranularity;
use Illuminate\Database\Eloquent\Model;

class ExecutiveMetricSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'metric_key',
        'snapshot_time',
        'metric_value',
        'status',
        'granularity',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_time' => 'datetime',
            'metric_value' => 'float',
            'granularity' => ExecutiveSnapshotGranularity::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
