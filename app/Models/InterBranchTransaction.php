<?php

namespace App\Models;

use App\Enums\InterBranchEwayBillStatus;
use App\Enums\InterBranchTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterBranchTransaction extends Model
{
    protected $fillable = [
        'transaction_no',
        'idempotency_key',
        'from_branch_id',
        'to_branch_id',
        'status',
        'destination_gstin',
        'statutory_invoice_id',
        'inventory_transfer_id',
        'inventory_reservation_id',
        'notes',
        'created_by',
        'issued_at',
        'dispatched_at',
        'received_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'transporter',
        'transport_reference',
        'dispatch_date',
        'eway_bill_reference',
        'eway_bill_status',
        'eway_bill_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InterBranchTransactionStatus::class,
            'eway_bill_status' => InterBranchEwayBillStatus::class,
            'issued_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'dispatch_date' => 'date',
        ];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'to_branch_id');
    }

    public function statutoryInvoice(): BelongsTo
    {
        return $this->belongsTo(StatutoryInvoice::class, 'statutory_invoice_id');
    }

    public function inventoryTransfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function inventoryReservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InterBranchTransactionLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isReconciled(): bool
    {
        return $this->statutory_invoice_id !== null
            && $this->inventory_transfer_id !== null
            && in_array($this->status, [
                InterBranchTransactionStatus::Received,
                InterBranchTransactionStatus::Completed,
            ], true);
    }
}
