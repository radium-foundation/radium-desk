<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentralWalletReconciliationRun extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'scope',
        'status',
        'started_at',
        'completed_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CentralWalletReconciliationItem::class, 'run_id');
    }
}
