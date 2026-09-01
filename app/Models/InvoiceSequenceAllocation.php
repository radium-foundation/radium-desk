<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class InvoiceSequenceAllocation extends Model
{
    protected $fillable = [
        'sequence_id',
        'allocated_number',
        'seq_int',
        'invoice_id',
        'idempotency_key',
        'allocated_by',
        'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'seq_int' => 'integer',
            'allocated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            foreach (['allocated_number', 'seq_int', 'sequence_id', 'idempotency_key'] as $field) {
                if ($allocation->isDirty($field)) {
                    throw ValidationException::withMessages([
                        'allocation' => 'Invoice number allocations are append-only and cannot be rewritten.',
                    ]);
                }
            }
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'allocation' => 'Invoice number allocations cannot be deleted.',
            ]);
        });
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(InvoiceSequence::class, 'sequence_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'invoice_id');
    }
}
