<?php

namespace App\Models;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\ServiceOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    protected $fillable = [
        'order_number',
        'quote_id',
        'customer_id',
        'branch_id',
        'buyer_name',
        'buyer_phone',
        'buyer_email',
        'buyer_gstin',
        'billing_address',
        'billing_state',
        'place_of_supply_state',
        'status',
        'payment_status',
        'subtotal',
        'tax_total',
        'discount',
        'total',
        'statutory_invoice_id',
        'idempotency_key',
        'created_by',
        'invoiced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceOrderStatus::class,
            'payment_status' => ServiceOrderPaymentStatus::class,
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'invoiced_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ServiceQuote::class, 'quote_id');
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
        return $this->hasMany(ServiceOrderLine::class, 'service_order_id')->orderBy('line_no');
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
