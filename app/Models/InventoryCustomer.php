<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
