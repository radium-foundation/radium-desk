<?php

namespace App\Models;

use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\OnlineReturnRefundAllocationLineExecutionStatus;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineReturnRefundAllocationLine extends Model
{
    protected $fillable = [
        'online_return_refund_allocation_id',
        'line_no',
        'destination',
        'amount',
        'execution_reference',
    ];

    protected function casts(): array
    {
        return [
            'destination' => OnlineReturnRefundAllocationDestination::class,
        ];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OnlineReturnRefundAllocation::class, 'online_return_refund_allocation_id');
    }

    public function executions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OnlineReturnRefundAllocationLineExecution::class, 'online_return_refund_allocation_line_id');
    }

    public function successfulExecution(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OnlineReturnRefundAllocationLineExecution::class, 'online_return_refund_allocation_line_id')
            ->where('status', OnlineReturnRefundAllocationLineExecutionStatus::Succeeded->value);
    }

    public function opmConversions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OnlineReturnRefundAllocationOpmConversion::class, 'online_return_refund_allocation_line_id');
    }
}
