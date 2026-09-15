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
        'signed_invoice_disk',
        'signed_invoice_path',
        'signed_invoice_sha256',
        'signed_invoice_bytes',
        'signed_invoice_persisted_at',
        'request_payload',
        'response_payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'ack_date' => 'datetime',
            'signed_invoice_bytes' => 'integer',
            'signed_invoice_persisted_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'invoice_id');
    }

    public function hasIssuedIrn(): bool
    {
        return is_string($this->irn) && trim($this->irn) !== '';
    }

    public function hasPersistedSignedInvoice(): bool
    {
        $path = is_string($this->signed_invoice_path) ? trim($this->signed_invoice_path) : '';
        $hash = is_string($this->signed_invoice_sha256) ? trim($this->signed_invoice_sha256) : '';

        return $path !== '' && strlen($hash) === 64 && (int) $this->signed_invoice_bytes > 0;
    }
}
