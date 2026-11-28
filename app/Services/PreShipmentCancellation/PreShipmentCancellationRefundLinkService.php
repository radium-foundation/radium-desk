<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\PreShipmentCancellationStatus;
use App\Models\PreShipmentCancellationRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundRequestService;
use App\Services\Refunds\HardwareOrderRefundCaseGate;
use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreShipmentCancellationRefundLinkService
{
    public function __construct(
        private readonly PreShipmentCancellationFeature $feature,
        private readonly RefundRequestService $refundRequests,
    ) {}

    public function approveAndLink(User $actor, PreShipmentCancellationRequest $case, Request $httpRequest): RefundRequest
    {
        $this->feature->assertEnabled();

        return DB::transaction(function () use ($actor, $case, $httpRequest): RefundRequest {
            $locked = PreShipmentCancellationRequest::query()
                ->whereKey($case->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->refund_request_id !== null) {
                return RefundRequest::query()->findOrFail($locked->refund_request_id);
            }

            if ($locked->status === PreShipmentCancellationStatus::HeldExistingInvoice
                && $locked->recorded_tax_outcome_type === null) {
                throw ValidationException::withMessages([
                    'tax' => 'Recorded tax outcome is required before linking a refund request.',
                ]);
            }

            $allowedStatuses = [
                PreShipmentCancellationStatus::FinanceApproved,
                PreShipmentCancellationStatus::FinanceReview,
            ];
            if (! in_array($locked->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Case must be Finance-approved before refund linkage.',
                ]);
            }

            if ($locked->split_tender && $locked->status !== PreShipmentCancellationStatus::FinanceApproved) {
                throw ValidationException::withMessages([
                    'split_tender' => 'Split-tender cases require explicit Finance approval before refund linkage.',
                ]);
            }

            $order = $locked->order ?? $locked->order()->firstOrFail();
            $metadata = PreShipmentCancellationOperatorMetadata::decode($locked->blocker_reason);
            $preferredMethod = match ($metadata['refund_preference'] ?? null) {
                'wallet' => CustomerPreferredRefundMethod::Wallet->value,
                'opm' => CustomerPreferredRefundMethod::Opm->value,
                default => CustomerPreferredRefundMethod::Opm->value,
            };

            $refund = $this->refundRequests->create($actor, [
                'order_id' => $order->id,
                'incident_id' => $locked->service_case_incident_id,
                'reason' => 'Pre-shipment cancellation '.$locked->reference_no,
                'remarks' => $locked->cancellation_reason,
                'amount' => (float) $locked->requested_refund_amount,
                'deduction_profile_key' => 'full_refund',
                'customer_preferred_method' => $preferredMethod,
                'communication_channels' => [],
                'hardware_refund_gate_bypass' => HardwareOrderRefundCaseGate::BYPASS_PRE_SHIPMENT_CANCELLATION,
                'pre_shipment_cancellation_request_id' => $locked->id,
            ], $httpRequest);

            $locked->forceFill([
                'refund_request_id' => $refund->id,
                'status' => PreShipmentCancellationStatus::RefundRequestLinked,
                'reviewed_by_user_id' => $actor->id,
                'finance_completed_by_user_id' => $actor->id,
            ])->save();

            $locked->forceFill([
                'status' => PreShipmentCancellationStatus::Completed,
                'terminal_at' => now(),
            ])->save();

            return $refund;
        });
    }
}
