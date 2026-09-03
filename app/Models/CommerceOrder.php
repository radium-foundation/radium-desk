<?php

namespace App\Models;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceOrder extends Model
{
    protected $fillable = [
        'order_no',
        'channel',
        'source_type',
        'source_id',
        'source_order_id',
        'idempotency_key',
        'payload_hash',
        'status',
        'invoice_eligible',
        'payment_status',
        'payment_provider',
        'payment_reference',
        'payment_method',
        'currency',
        'customer_name',
        'customer_phone',
        'customer_email',
        'buyer_gstin',
        'billing_address',
        'shipping_address',
        'seller_gstin',
        'seller_name',
        'branch_code',
        'place_of_supply_state',
        'taxable_value',
        'discount',
        'tax_total',
        'order_value',
        'metadata',
        'ordered_at',
        'paid_at',
        'received_at',
        'status_reason',
        'statutory_invoice_id',
        'support_order_id',
    ];

    protected function casts(): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::class,
            'status' => CommerceOrderStatus::class,
            'invoice_eligible' => 'boolean',
            'taxable_value' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'order_value' => 'decimal:2',
            'metadata' => 'array',
            'ordered_at' => 'datetime',
            'paid_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommerceOrderItem::class)->orderBy('line_no');
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }
}
