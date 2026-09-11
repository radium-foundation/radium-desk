<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCustomerBillingProfile extends Model
{
    public const SOURCE_LAST_ADMIN_POS_ORDER = 'last_admin_pos_order';

    protected $fillable = [
        'customer_id',
        'line1',
        'city',
        'state',
        'pincode',
        'source',
        'source_legacy_order_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(InventoryCustomer::class, 'customer_id');
    }
}
