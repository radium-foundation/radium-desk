<?php

namespace App\Models;

use App\Enums\FinancePartyRoleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePartyRole extends Model
{
    protected $table = 'finance_party_roles';

    protected $fillable = [
        'party_id',
        'role',
        'vendor_code',
        'payment_terms',
        'credit_days',
        'credit_limit',
        'preferred_payment_method',
    ];

    protected function casts(): array
    {
        return [
            'role' => FinancePartyRoleType::class,
            'credit_days' => 'integer',
            'credit_limit' => 'decimal:2',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    public function isCustomer(): bool
    {
        return $this->role === FinancePartyRoleType::Customer;
    }

    public function isVendor(): bool
    {
        return $this->role === FinancePartyRoleType::Vendor;
    }
}
