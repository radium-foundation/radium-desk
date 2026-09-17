<?php

namespace App\Models;

use App\Enums\RefundRevocationAttemptStatus;
use App\Enums\RefundRevokeCustomerOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRevocationAttempt extends Model
{
    protected $fillable = [
        'refund_request_id',
        'idempotency_key',
        'customer_outcome',
        'revoke_reason',
        'status',
        'wallet_reversal_reference',
        'wallet_reversal_transaction_id',
        'commercial_service_restoration_id',
        'actor_user_id',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'customer_outcome' => RefundRevokeCustomerOutcome::class,
            'status' => RefundRevocationAttemptStatus::class,
            'metadata' => 'array',
        ];
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function commercialServiceRestoration(): BelongsTo
    {
        return $this->belongsTo(CommercialServiceRestoration::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === RefundRevocationAttemptStatus::Completed;
    }

    public function hasWalletReversal(): bool
    {
        return in_array($this->status, [
            RefundRevocationAttemptStatus::WalletReversed,
            RefundRevocationAttemptStatus::Completed,
        ], true);
    }
}
