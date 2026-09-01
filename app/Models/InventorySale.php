<?php

namespace App\Models;

use App\Enums\InventoryFinanceHandoffStatus;
use App\Enums\InventorySaleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySale extends Model
{
    protected $fillable = [
        'sale_no',
        'invoice_number',
        'branch_id',
        'customer_id',
        'support_order_id',
        'status',
        'subtotal',
        'discount',
        'tax',
        'total',
        'payment_method',
        'payment_reference',
        'finance_handoff_status',
        'finance_journal_id',
        'notes',
        'cancel_reason',
        'created_by',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventorySaleStatus::class,
            'finance_handoff_status' => InventoryFinanceHandoffStatus::class,
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(InventoryCustomer::class, 'customer_id');
    }

    public function supportOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'support_order_id');
    }

    public function financeJournal(): BelongsTo
    {
        return $this->belongsTo(FinanceJournal::class, 'finance_journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventorySaleLine::class, 'sale_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(InventorySaleSerial::class, 'sale_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
