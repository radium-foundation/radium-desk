<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class CashfreeHistoricalIdentityRepairCohortAudit extends Model
{
    protected $table = 'cashfree_historical_identity_repair_cohort_audits';

    protected $fillable = [
        'run_id',
        'normalized_email',
        'subject_hash',
        'order_ids',
        'previous_customer_ids',
        'target_desk_customer_id',
        'central_wallet_id',
        'action',
        'status',
        'refund_exposure',
        'error_reason',
        'planned_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'order_ids' => 'array',
            'previous_customer_ids' => 'array',
            'planned_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
