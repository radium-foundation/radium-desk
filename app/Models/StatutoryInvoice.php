<?php

namespace App\Models;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class StatutoryInvoice extends Model
{
    /**
     * @var list<string>
     */
    private const IMMUTABLE_AFTER_ISSUE = [
        'invoice_number',
        'sequence_allocation_id',
        'document_type',
        'channel',
        'source_type',
        'source_id',
        'source_order_id',
        'idempotency_key',
        'inventory_sale_id',
        'support_order_id',
        'branch_id',
        'seller_gstin',
        'seller_name',
        'buyer_name',
        'buyer_phone',
        'buyer_gstin',
        'billing_address',
        'place_of_supply_state',
        'taxable_value',
        'discount',
        'tax_total',
        'cgst',
        'sgst',
        'igst',
        'rounding',
        'invoice_value',
        'payment_method',
        'payment_reference',
        'finance_journal_id',
        'issued_by',
        'issued_at',
    ];

    protected $fillable = [
        'invoice_number',
        'sequence_allocation_id',
        'document_type',
        'status',
        'channel',
        'source_type',
        'source_id',
        'source_order_id',
        'idempotency_key',
        'inventory_sale_id',
        'support_order_id',
        'branch_id',
        'seller_gstin',
        'seller_name',
        'buyer_name',
        'buyer_phone',
        'buyer_gstin',
        'billing_address',
        'place_of_supply_state',
        'taxable_value',
        'discount',
        'tax_total',
        'cgst',
        'sgst',
        'igst',
        'rounding',
        'invoice_value',
        'payment_method',
        'payment_reference',
        'finance_journal_id',
        'issued_by',
        'issued_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => StatutoryInvoiceDocumentType::class,
            'status' => StatutoryInvoiceStatus::class,
            'channel' => StatutoryInvoiceChannel::class,
            'taxable_value' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'rounding' => 'decimal:2',
            'invoice_value' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            foreach (self::IMMUTABLE_AFTER_ISSUE as $field) {
                if ($invoice->isDirty($field)) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Posted statutory invoices cannot be mutated. Cancel with a credit-note flow instead.',
                    ]);
                }
            }

            if ($invoice->isDirty('status') && $invoice->getOriginal('status') === StatutoryInvoiceStatus::Cancelled->value) {
                throw ValidationException::withMessages([
                    'status' => 'A cancelled statutory invoice cannot be reopened.',
                ]);
            }
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'invoice' => 'Statutory invoices cannot be deleted.',
            ]);
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(StatutoryInvoiceItem::class, 'invoice_id')->orderBy('line_no');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(InvoiceSequenceAllocation::class, 'sequence_allocation_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function inventorySale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'inventory_sale_id');
    }

    public function eInvoiceRecord(): HasOne
    {
        return $this->hasOne(EInvoiceRecord::class, 'invoice_id');
    }

    public function document(): HasOne
    {
        return $this->hasOne(StatutoryInvoiceDocument::class, 'invoice_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
