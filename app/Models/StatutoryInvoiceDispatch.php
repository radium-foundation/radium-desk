<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryInvoiceDispatch extends Model
{
    protected $fillable = [
        'invoice_id',
        'channel',
        'destination',
        'sent_by',
        'sent_at',
        'status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'invoice_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
