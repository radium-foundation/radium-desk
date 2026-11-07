<?php

namespace App\Models;

use App\Enums\PosCustomerRequestedSerialExchangeStatus;
use App\Enums\PosCustomerRequestedSerialExchangeStatutoryTreatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCustomerRequestedSerialExchange extends Model
{
    protected $fillable = [
        'inventory_sale_id',
        'inventory_branch_id',
        'original_inventory_serial_id',
        'replacement_inventory_serial_id',
        'inventory_sale_serial_id',
        'idempotency_key',
        'exchange_reason',
        'status',
        'physical_receipt_attestation_reason',
        'physical_receipt_attested_by',
        'physical_receipt_attested_at',
        'statutory_treatment_decision',
        'statutory_evidence_reference',
        'statutory_notes',
        'statutory_approved_by',
        'statutory_approved_at',
        'performed_by',
        'executed_at',
        'sale_total_snapshot',
        'sale_serial_count_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => PosCustomerRequestedSerialExchangeStatus::class,
            'statutory_treatment_decision' => PosCustomerRequestedSerialExchangeStatutoryTreatment::class,
            'physical_receipt_attested_at' => 'datetime',
            'statutory_approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'sale_total_snapshot' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'inventory_sale_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'inventory_branch_id');
    }

    public function originalSerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'original_inventory_serial_id');
    }

    public function replacementSerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'replacement_inventory_serial_id');
    }

    public function saleSerialAssignment(): BelongsTo
    {
        return $this->belongsTo(InventorySaleSerial::class, 'inventory_sale_serial_id');
    }

    public function physicalReceiptAttestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physical_receipt_attested_by');
    }

    public function statutoryApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'statutory_approved_by');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
