<?php

namespace App\Models;

use App\Enums\RadiumBox\RadiumBoxStatutoryReceiptPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiumBoxStatutoryReceiptPublication extends Model
{
    protected $table = 'radiumbox_statutory_receipt_publications';

    protected $fillable = [
        'statutory_invoice_id',
        'http_idempotency_key',
        'status',
        'box_receipt_id',
        'last_http_status',
        'duplicate_delivery',
        'eligibility_code',
        'last_error',
        'attempt_count',
        'delivered_at',
        'last_outbox_event_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => RadiumBoxStatutoryReceiptPublicationStatus::class,
            'duplicate_delivery' => 'boolean',
            'delivered_at' => 'datetime',
        ];
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function isTerminalSuccess(): bool
    {
        return in_array($this->status, [
            RadiumBoxStatutoryReceiptPublicationStatus::Delivered,
            RadiumBoxStatutoryReceiptPublicationStatus::DuplicateDelivered,
        ], true);
    }
}
