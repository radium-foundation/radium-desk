<?php

namespace App\Models;

use App\Enums\PurchasePaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    protected $fillable = [
        'vendor_id',
        'purchase_order_id',
        'goods_receipt_id',
        'supplier_invoice_number',
        'invoice_date',
        'invoice_amount',
        'taxable_amount',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'payment_status',
        'amount_paid',
        'notes',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'invoice_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'payment_status' => PurchasePaymentStatus::class,
            'amount_paid' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PurchasingDocument::class, 'related_id')
            ->where('related_type', self::class);
    }
}
