<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatutoryInvoiceRemediationRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'remediation_key',
        'statutory_invoice_id',
        'invoice_number',
        'prompt_id',
        'actor',
        'evidence_reference',
        'before_snapshot',
        'after_snapshot',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'applied_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
