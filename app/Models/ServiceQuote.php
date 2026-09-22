<?php

namespace App\Models;

use App\Enums\ServiceQuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceQuote extends Model
{
    protected $fillable = [
        'quote_number',
        'status',
        'customer_id',
        'branch_id',
        'buyer_name',
        'buyer_phone',
        'buyer_email',
        'buyer_gstin',
        'billing_address',
        'billing_address_structured',
        'billing_state',
        'place_of_supply_state',
        'payment_reference',
        'subtotal',
        'tax_total',
        'discount',
        'total',
        'valid_until',
        'converted_service_order_id',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceQuoteStatus::class,
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'datetime',
            'billing_address_structured' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(InventoryCustomer::class, 'customer_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServiceQuoteLine::class, 'quote_id')->orderBy('line_no');
    }

    public function serviceOrder(): HasOne
    {
        return $this->hasOne(ServiceOrder::class, 'quote_id');
    }

    public function convertedServiceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'converted_service_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
