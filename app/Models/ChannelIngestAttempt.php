<?php

namespace App\Models;

use App\Enums\ChannelIngestOutcome;
use App\Enums\StatutoryInvoiceChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelIngestAttempt extends Model
{
    protected $fillable = [
        'channel',
        'source_type',
        'source_id',
        'idempotency_key',
        'payload_hash',
        'outcome',
        'http_status',
        'signature_ok',
        'commerce_order_id',
        'statutory_invoice_id',
        'invoice_number',
        'error',
        'remote_ip',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::class,
            'outcome' => ChannelIngestOutcome::class,
            'signature_ok' => 'boolean',
            'http_status' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }
}
