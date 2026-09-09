<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePartyGstRegistration extends Model
{
    protected $table = 'finance_party_gst_registrations';

    protected $fillable = [
        'party_id',
        'gstin',
        'registered_name',
        'state',
        'state_code',
        'pan',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }
}
