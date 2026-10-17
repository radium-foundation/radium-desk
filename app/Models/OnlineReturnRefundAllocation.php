<?php

namespace App\Models;

use App\Enums\OnlineReturnRefundAllocationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineReturnRefundAllocation extends Model
{
    protected $fillable = [
        'return_exchange_case_id',
        'order_id',
        'hardware_fulfilment_id',
        'reference_no',
        'status',
        'returned_line_cap',
        'order_paid_total',
        'prior_refunds_total',
        'max_allocatable_amount',
        'requested_total',
        'currency',
        'requester_remarks',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnlineReturnRefundAllocationStatus::class,
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCase::class, 'return_exchange_case_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OnlineReturnRefundAllocationLine::class)->orderBy('line_no');
    }
}
