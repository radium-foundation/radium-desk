<?php

namespace App\CentralWallet\Infrastructure\Persistence;

use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use Illuminate\Database\Eloquent\Model;

final class CentralWalletBalanceMigration extends Model
{
    protected $table = 'central_wallet_balance_migrations';

    protected $primaryKey = 'migration_operation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'migration_operation_id',
        'migration_batch_id',
        'status',
        'source_site_code',
        'source_local_user_id',
        'source_users_wallet_id',
        'source_order_reference',
        'source_business_reference',
        'source_amount',
        'source_currency',
        'source_created_at',
        'destination_central_wallet_id',
        'destination_ledger_entry_id',
        'source_retirement_reference',
        'idempotency_key',
        'owner_approval_ref',
        'actor_id',
        'correlation_id',
        'metadata',
        'failure_code',
        'failure_message',
        'prepared_at',
        'central_credited_at',
        'source_retired_at',
        'reconciled_at',
        'aborted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BalanceMigrationStatus::class,
            'source_amount' => 'string',
            'source_created_at' => 'datetime',
            'metadata' => 'array',
            'prepared_at' => 'datetime',
            'central_credited_at' => 'datetime',
            'source_retired_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'aborted_at' => 'datetime',
        ];
    }
}
