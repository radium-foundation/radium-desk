<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWalletReconciliationItem extends Model
{
    protected $fillable = [
        'run_id',
        'item_type',
        'severity',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CentralWalletReconciliationRun::class, 'run_id');
    }
}
