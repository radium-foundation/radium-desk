<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryInvoiceCancellation extends Model
{
    protected $fillable = [
        'statutory_invoice_id',
        'idempotency_key',
        'actor_id',
        'reason',
        'irn_action',
        'inventory_action',
        'credit_note_action',
        'result_summary',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'irn_action' => 'array',
            'inventory_action' => 'array',
            'credit_note_action' => 'array',
            'result_summary' => 'array',
            'completed_at' => 'datetime',
        ];
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
