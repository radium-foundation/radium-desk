<?php

namespace App\Models;

use App\Enums\RefundStatutoryAdjustmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundStatutoryAdjustment extends Model
{
    protected $fillable = [
        'refund_request_id',
        'statutory_invoice_id',
        'status',
        'idempotency_key',
        'skip_reason',
        'orchestrator_result',
        'outbox_event_id',
        'failure_reason',
        'attempts',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatutoryAdjustmentStatus::class,
            'orchestrator_result' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'refund_request_id');
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }
}
