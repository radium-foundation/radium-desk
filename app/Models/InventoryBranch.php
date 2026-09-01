<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBranch extends Model
{
    protected $fillable = [
        'code',
        'name',
        'gstin',
        'is_active',
        'invoice_sequence',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'invoice_sequence' => 'integer',
        ];
    }

    public function serials(): HasMany
    {
        return $this->hasMany(InventorySerial::class, 'branch_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class, 'branch_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(InventorySale::class, 'branch_id');
    }
}
