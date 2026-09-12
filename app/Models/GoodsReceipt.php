<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    protected $fillable = [
        'receipt_number',
        'receipt_date',
        'purchase_order_id',
        'vendor_id',
        'branch_id',
        'status',
        'supplier_challan_reference',
        'notes',
        'received_by_user_id',
        'completed_by_user_id',
        'completed_at',
        'completion_idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'status' => GoodsReceiptStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(GoodsReceiptSerial::class);
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
