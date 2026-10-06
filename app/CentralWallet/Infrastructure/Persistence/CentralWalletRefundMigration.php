<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWalletRefundMigration extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'batch_id',
        'refund_id',
        'refund_reference',
        'amount',
        'source_type',
        'source_application',
        'source_wallet_id',
        'source_reference',
        'desk_customer_id',
        'cwid',
        'lane',
        'status',
        'idempotency_key',
        'owner_approval_ref',
        'destination_ledger_entry_id',
        'reversal_ledger_entry_id',
        'source_debit_reference',
        'balance_migration_operation_id',
        'order_number',
        'identity_class',
        'metadata',
        'error_code',
        'error_message',
        'prepared_at',
        'executed_at',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RefundMigrationStatus::class,
            'lane' => RefundMigrationLane::class,
            'metadata' => 'array',
            'prepared_at' => 'datetime',
            'executed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CentralCustomer::class, 'desk_customer_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CentralWallet::class, 'cwid');
    }
}
