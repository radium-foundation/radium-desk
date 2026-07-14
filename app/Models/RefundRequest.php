<?php

namespace App\Models;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundDifferenceReason;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RefundRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'incident_id',
        'reference_no',
        'amount',
        'reason',
        'customer_preferred_method',
        'approved_refund_method',
        'status',
        'total_paid_amount',
        'already_refunded_amount',
        'maximum_refundable',
        'cancellation_charges',
        'gst_on_cancellation',
        'other_deduction',
        'total_deduction',
        'refund_amount',
        'deduction_profile_key',
        'partial_difference_reason',
        'partial_difference_notes',
        'refund_transaction_id',
        'execution_reference_no',
        'execution_transaction_id',
        'execution_remarks',
        'executed_by',
        'executed_at',
        'closed_at',
        'communication_channels',
        'deduction_snapshot',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'total_paid_amount' => 'decimal:2',
            'already_refunded_amount' => 'decimal:2',
            'maximum_refundable' => 'decimal:2',
            'cancellation_charges' => 'decimal:2',
            'gst_on_cancellation' => 'decimal:2',
            'other_deduction' => 'decimal:2',
            'total_deduction' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'status' => RefundStatus::class,
            'customer_preferred_method' => CustomerPreferredRefundMethod::class,
            'approved_refund_method' => ApprovedRefundMethod::class,
            'deduction_profile_key' => RefundDeductionProfile::class,
            'partial_difference_reason' => RefundDifferenceReason::class,
            'communication_channels' => 'array',
            'deduction_snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'executed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function remarks(): MorphMany
    {
        return $this->morphMany(Remark::class, 'remarkable');
    }

    public function displayAmount(): float
    {
        if ($this->refund_amount !== null) {
            return (float) $this->refund_amount;
        }

        return (float) $this->amount;
    }

    public function effectiveTransactionId(): ?string
    {
        $execution = trim((string) ($this->execution_transaction_id ?? ''));
        if ($execution !== '') {
            return $execution;
        }

        $legacy = trim((string) ($this->refund_transaction_id ?? ''));

        return $legacy !== '' ? $legacy : null;
    }
}
