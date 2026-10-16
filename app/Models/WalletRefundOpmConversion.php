<?php

namespace App\Models;

use App\Enums\WalletRefundCreditSource;
use App\Enums\WalletRefundOpmConversionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletRefundOpmConversion extends Model
{
    protected $fillable = [
        'public_id',
        'refund_request_id',
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
        'cashfree_order_id',
        'cashfree_refund_id',
        'cashfree_refund_amount',
        'cashfree_refund_status',
        'cashfree_verified_at',
        'wallet_finalization_idempotency_key',
        'failure_code',
        'failure_message',
        'reconciliation_entered_at',
    ];

    protected $casts = [
        'status' => WalletRefundOpmConversionStatus::class,
        'wallet_credit_source' => WalletRefundCreditSource::class,
        'amount' => 'decimal:2',
        'cashfree_refund_amount' => 'decimal:2',
        'cashfree_verified_at' => 'datetime',
        'reconciliation_entered_at' => 'datetime',
    ];

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function payoutOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payout_operator_id');
    }
}
