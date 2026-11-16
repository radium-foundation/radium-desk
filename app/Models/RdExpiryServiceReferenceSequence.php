<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RdExpiryServiceReferenceSequence extends Model
{
    protected $fillable = [
        'expiry_date',
        'last_allocated_sequence',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'last_allocated_sequence' => 'integer',
        ];
    }
}