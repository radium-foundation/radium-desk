<?php

namespace App\Models;

use App\Enums\PreShipmentCancellationOrderFamily;
use App\Enums\PreShipmentCancellationServiceCaseSyncStatus;
use App\Enums\PreShipmentCancellationShipmentVerification;
use App\Enums\PreShipmentCancellationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreShipmentCancellationRequest extends Model
{
    protected $fillable = [
        'reference_no',
        'order_id',
        'commerce_order_id',
        'hardware_fulfilment_id',
        'source_id',
        'order_family',
        'phase_scope',
        'status',
        'sub_status',
        'blocker_reason',
        'cancellation_reason',
        'submitted_by_user_id',
        'reviewed_by_user_id',
        'finance_completed_by_user_id',
        'shipment_verification',
        'order_total_amount',
        'wallet_tender_amount',
        'external_tender_amount',
        'already_refunded_amount',
        'maximum_refundable_amount',
        'requested_refund_amount',
        'split_tender',
        'execution_hold',
        'execution_hold_reason',
        'refund_request_id',
        'recorded_tax_statutory_invoice_id',
        'recorded_tax_outcome_type',
        'service_case_incident_id',
        'service_case_outcome',
        'service_case_sync_status',
        'idempotency_key',
        'ingestion_block_version',
        'committed_at',
        'terminal_at',
        'future_spoke_sync_status',
        'future_spoke_sync_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => PreShipmentCancellationStatus::class,
            'order_family' => PreShipmentCancellationOrderFamily::class,
            'shipment_verification' => PreShipmentCancellationShipmentVerification::class,
            'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::class,
            'order_total_amount' => 'decimal:2',
            'wallet_tender_amount' => 'decimal:2',
            'external_tender_amount' => 'decimal:2',
            'already_refunded_amount' => 'decimal:2',
            'maximum_refundable_amount' => 'decimal:2',
            'requested_refund_amount' => 'decimal:2',
            'split_tender' => 'boolean',
            'execution_hold' => 'boolean',
            'service_case_outcome' => 'array',
            'future_spoke_sync_payload' => 'array',
            'committed_at' => 'datetime',
            'terminal_at' => 'datetime',
            'ingestion_block_version' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function hardwareFulfilment(): BelongsTo
    {
        return $this->belongsTo(HardwareFulfilment::class);
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    public function serviceCaseIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'service_case_incident_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
