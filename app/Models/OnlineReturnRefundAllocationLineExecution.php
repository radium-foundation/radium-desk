<?php

namespace App\Models;

use App\Enums\OnlineReturnRefundAllocationLineExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineReturnRefundAllocationLineExecution extends Model
{
    protected $fillable = [
        'online_return_refund_allocation_line_id',
        'status',
        'executor_provider',
        'idempotency_key',
        'execution_reference',
        'transaction_id',
        'failure_reason',
        'held_reason',
        'metadata',
        'executed_by',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnlineReturnRefundAllocationLineExecutionStatus::class,
            'metadata' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OnlineReturnRefundAllocationLine::class, 'online_return_refund_allocation_line_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
