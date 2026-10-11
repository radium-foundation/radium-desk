<?php

namespace App\Models;

use App\Enums\LegacyCashfreePaymentReconciliationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyCashfreePaymentReconciliation extends Model
{
    protected $fillable = [
        'order_id',
        'business_order_id',
        'legacy_source',
        'state',
        'discovery_classification',
        'reason',
        'attempt_count',
        'last_attempt_at',
        'next_attempt_at',
        'cf_payment_id',
        'finance_journal_id',
        'audit_meta',
    ];

    protected function casts(): array
    {
        return [
            'state' => LegacyCashfreePaymentReconciliationState::class,
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'audit_meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
