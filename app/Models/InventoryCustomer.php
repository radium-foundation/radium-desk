<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS till customer keyed by unique phone. This is not the Finance Party master.
 * Future POS sales may store an optional finance_parties.id snapshot key without
 * replacing this table or live-joining it into issued invoices.
 */
class InventoryCustomer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'gstin',
        'finance_party_id',
    ];

    public function financeParty(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'finance_party_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(InventorySale::class, 'customer_id');
    }
}
