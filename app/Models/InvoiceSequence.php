<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceSequence extends Model
{
    protected $fillable = [
        'sequence_key',
        'series_code',
        'document_type',
        'gstin_scope',
        'financial_year',
        'current_value',
    ];

    protected function casts(): array
    {
        return [
            'current_value' => 'integer',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InvoiceSequenceAllocation::class, 'sequence_id');
    }
}
