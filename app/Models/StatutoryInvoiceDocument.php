<?php

namespace App\Models;

use App\Enums\StatutoryInvoiceDocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryInvoiceDocument extends Model
{
    protected $fillable = [
        'invoice_id',
        'status',
        'disk',
        'path',
        'content_type',
        'checksum',
        'attempts',
        'last_error',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatutoryInvoiceDocumentStatus::class,
            'generated_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'invoice_id');
    }
}
