<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryCustomer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'gstin',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(InventorySale::class, 'customer_id');
    }

    public function billingProfile(): HasOne
    {
        return $this->hasOne(InventoryCustomerBillingProfile::class, 'customer_id');
    }
}
