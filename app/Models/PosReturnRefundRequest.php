<?php

namespace App\Models;

use App\Enums\PosReturnRefundRequestStatus;
use App\Enums\PosReturnRefundTaxEvidenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosReturnRefundRequest extends Model
{
    protected $fillable = [
        'return_exchange_case_id',
        'inventory_sale_id',
        'reference_no',
        'amount',
        'payment_method',
        'payment_reference',
        'status',
        'statutory_invoice_id',
        'tax_evidence_type',
        'requester_remarks',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PosReturnRefundRequestStatus::class,
            'tax_evidence_type' => PosReturnRefundTaxEvidenceType::class,
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCase::class, 'return_exchange_case_id');
    }

    public function inventorySale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'inventory_sale_id');
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
