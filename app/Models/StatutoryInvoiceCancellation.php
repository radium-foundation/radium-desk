<?php

namespace App\Models;

use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryInvoiceCancellation extends Model
{
    protected $fillable = [
        'statutory_invoice_id',
        'idempotency_key',
        'actor_id',
        'reason',
        'status',
        'correlation_id',
        'provider_reference',
        'attempt_count',
        'last_error',
        'irn_confirmed_at',
        'irn_action',
        'inventory_action',
        'credit_note_action',
        'result_summary',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatutoryInvoiceCancellationOperationStatus::class,
            'irn_action' => 'array',
            'inventory_action' => 'array',
            'credit_note_action' => 'array',
            'result_summary' => 'array',
            'irn_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === StatutoryInvoiceCancellationOperationStatus::Completed
            || $this->completed_at !== null;
    }

    public function requiresManualReview(): bool
    {
        return $this->status === StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
