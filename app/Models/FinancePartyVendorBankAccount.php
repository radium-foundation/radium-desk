<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePartyVendorBankAccount extends Model
{
    protected $table = 'finance_party_vendor_bank_accounts';

    protected $hidden = [
        'account_number',
        'ifsc',
    ];

    protected $fillable = [
        'party_id',
        'bank_name',
        'account_holder_name',
        'account_number',
        'last_four',
        'ifsc',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'ifsc' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = parent::toArray();
        unset($payload['account_number'], $payload['ifsc']);

        return $payload;
    }
}
