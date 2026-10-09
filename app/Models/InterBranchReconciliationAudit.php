<?php

namespace App\Models;

use App\Enums\InterBranchReconciliationMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterBranchReconciliationAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'inter_branch_transaction_id',
        'actor_user_id',
        'inventory_sale_id',
        'statutory_invoice_id',
        'from_branch_id',
        'to_branch_id',
        'serial_count',
        'reconciliation_mode',
        'idempotency_key',
        'reason',
        'finance_treatment',
        'finance_journal_id',
        'before_state',
        'after_state',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reconciliation_mode' => InterBranchReconciliationMode::class,
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InterBranchTransaction::class, 'inter_branch_transaction_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'inventory_sale_id');
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'to_branch_id');
    }

    public function financeJournal(): BelongsTo
    {
        return $this->belongsTo(FinanceJournal::class, 'finance_journal_id');
    }
}
