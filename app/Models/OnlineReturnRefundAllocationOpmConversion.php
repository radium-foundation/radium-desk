<?php

namespace App\Models;

use App\Enums\AllocationOpmReservationState;
use App\Enums\AllocationOpmWalletFinalizationState;
use App\Enums\WalletRefundCreditSource;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Services\WalletRefundOpmConversion\Contracts\CashfreeOpmPayoutEvidence;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSpokeConversion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineReturnRefundAllocationOpmConversion extends Model implements CashfreeOpmPayoutEvidence, WalletRefundOpmSpokeConversion
{
    protected $fillable = [
        'public_id',
        'return_exchange_case_id',
        'online_return_refund_allocation_id',
        'online_return_refund_allocation_line_id',
        'online_return_refund_allocation_line_execution_id',
        'desk_wallet_reference',
        'wallet_executor_provider',
        'wallet_execution_reference',
        'wallet_transaction_id',
        'bound_order_id',
        'bound_wallet_credit_amount',
        'submit_idempotency_key',
        'status',
        'site_code',
        'wallet_credit_source',
        'amount',
        'currency',
        'requester_id',
        'approver_id',
        'payout_operator_id',
        'site_reservation_id',
        'site_reservation_status',
        'site_reservation_idempotency_key',
        'site_reservation_state',
        'site_reservation_attempted_at',
        'site_reservation_may_exist',
        'site_reservation_released_at',
        'site_reservation_release_outcome',
        'site_reservation_release_error',
        'cashfree_order_id',
        'cashfree_refund_id',
        'cashfree_refund_amount',
        'cashfree_refund_status',
        'cashfree_verified_at',
        'wallet_finalization_idempotency_key',
        'wallet_finalization_state',
        'wallet_finalization_attempted_at',
        'wallet_finalization_confirmed_at',
        'wallet_finalization_idempotent_replay',
        'wallet_finalization_last_error',
        'wallet_reversal_reference',
        'failure_code',
        'failure_message',
        'reconciliation_entered_at',
        'last_reconciliation_at',
        'last_reconciliation_by_id',
        'last_reconciliation_action',
        'last_reconciliation_outcome',
        'last_reconciliation_detail',
    ];

    protected function casts(): array
    {
        return [
            'status' => WalletRefundOpmConversionStatus::class,
            'wallet_credit_source' => WalletRefundCreditSource::class,
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::class,
            'site_reservation_state' => AllocationOpmReservationState::class,
            'site_reservation_may_exist' => 'boolean',
            'wallet_finalization_idempotent_replay' => 'boolean',
            'amount' => 'decimal:2',
            'bound_wallet_credit_amount' => 'decimal:2',
            'cashfree_refund_amount' => 'decimal:2',
            'cashfree_verified_at' => 'datetime',
            'reconciliation_entered_at' => 'datetime',
            'site_reservation_attempted_at' => 'datetime',
            'last_reconciliation_at' => 'datetime',
        ];
    }

    public function lastReconciliationBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reconciliation_by_id');
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCase::class, 'return_exchange_case_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OnlineReturnRefundAllocation::class, 'online_return_refund_allocation_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OnlineReturnRefundAllocationLine::class, 'online_return_refund_allocation_line_id');
    }

    public function walletExecution(): BelongsTo
    {
        return $this->belongsTo(OnlineReturnRefundAllocationLineExecution::class, 'online_return_refund_allocation_line_execution_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function getPublicId(): string
    {
        return (string) $this->public_id;
    }

    public function getAmount(): string
    {
        return (string) $this->amount;
    }

    public function getCurrency(): string
    {
        return (string) ($this->currency ?? 'INR');
    }

    public function getSiteReservationId(): ?string
    {
        return $this->site_reservation_id;
    }

    public function getWalletFinalizationIdempotencyKey(): ?string
    {
        return $this->wallet_finalization_idempotency_key;
    }

    public function getWalletCreditSource(): ?WalletRefundCreditSource
    {
        return $this->wallet_credit_source;
    }

    public function getCashfreeOrderId(): ?string
    {
        return $this->cashfree_order_id;
    }

    public function getCashfreeRefundId(): ?string
    {
        return $this->cashfree_refund_id;
    }

    public function getCashfreeRefundAmount(): ?string
    {
        return $this->cashfree_refund_amount !== null ? (string) $this->cashfree_refund_amount : null;
    }
}
