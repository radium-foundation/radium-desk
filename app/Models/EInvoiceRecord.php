<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EInvoiceRecord extends Model
{
    protected $fillable = [
        'invoice_id',
        'provider',
        'irn',
        'ack_no',
        'ack_date',
        'signed_qr',
        'request_payload',
        'response_payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'ack_date' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'invoice_id');
    }
}
