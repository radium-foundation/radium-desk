<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    protected $fillable = [
        'vendor_code',
        'business_name',
        'legal_name',
        'gstin',
        'pan',
        'phone',
        'email',
        'billing_address',
        'city',
        'state',
        'country',
        'pin',
        'is_active',
        'notes',
        'legacy_source_database',
        'legacy_source_table',
        'legacy_supplier_id',
        'legacy_import_batch',
        'legacy_imported_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'legacy_imported_at' => 'datetime',
        ];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function displayName(): string
    {
        return $this->business_name;
    }
}
