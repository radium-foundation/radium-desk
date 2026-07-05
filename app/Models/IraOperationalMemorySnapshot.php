<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IraOperationalMemorySnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'operations',
        'team',
        'performance',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'operations' => 'array',
            'team' => 'array',
            'performance' => 'array',
        ];
    }
}
