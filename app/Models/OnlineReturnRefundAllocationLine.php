<?php

namespace App\Models;

use App\Enums\OnlineReturnRefundAllocationDestination;
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
}
