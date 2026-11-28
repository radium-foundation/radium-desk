<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\HardwareFulfilmentState;
use App\Enums\PreShipmentCancellationOrderFamily;
use App\Enums\PreShipmentCancellationServiceCaseSyncStatus;
use App\Enums\PreShipmentCancellationShipmentVerification;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\PreShipmentEligibilityFailureCode;
use App\Jobs\PreShipmentCancellation\ClosePreShipmentCancellationIncidentJob;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\Orders\OrderPaymentBreakdownResolver;
use App\Services\Refunds\OrderStatutoryInvoiceResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreShipmentCancellationSubmitService
{
    public function __construct(
        private readonly PreShipmentCancellationFeature $feature,
        private readonly PreShipmentCancellationEligibilityService $eligibility,
        private readonly OrderPaymentBreakdownResolver $paymentBreakdown,
        private readonly HardwareFulfilmentWorkflowService $fulfilmentWorkflow,
        private readonly PreShipmentCancellationIncidentService $incidentService,
        private readonly OrderStatutoryInvoiceResolver $invoiceResolver,
        private readonly PreShipmentCancellationRefundAutomationService $refundAutomation,
    ) {}

    /**
     * @param  array{cancellation_reason: string, blocker_reason?: string|null, shipment_verification?: string|null, requested_refund_amount?: float|null, idempotency_key: string}  $payload
     */
    public function submit(User $actor, Order $order, array $payload): PreShipmentCancellationRequest
    {
        $this->feature->assertEnabled();

        $existing = PreShipmentCancellationRequest::query()
            ->where('idempotency_key', (string) $payload['idempotency_key'])
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $assessment = $this->eligibility->assess($order);
        if (! $assessment['eligible']) {
            throw ValidationException::withMessages([
                'order_id' => 'Order is not eligible for pre-shipment cancellation.',
                'eligibility_code' => $assessment['code']?->value,
            ]);
        }

        $commerce = $assessment['commerce'];
        $breakdown = $this->paymentBreakdown->resolve($order, $commerce);
        $requested = isset($payload['requested_refund_amount'])
            ? round((float) $payload['requested_refund_amount'], 2)
            : $breakdown->maximumRefundable;

        if ($requested > $breakdown->maximumRefundable + 0.01) {
            throw ValidationException::withMessages([
                'requested_refund_amount' => 'Requested refund exceeds the verified maximum refundable amount.',
            ]);
        }

        try {
            $case = DB::transaction(function () use ($actor, $order, $payload, $assessment, $commerce, $breakdown, $requested): PreShipmentCancellationRequest {
                $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $lockedCommerce = null;
                if ($commerce !== null) {
                    $lockedCommerce = CommerceOrder::query()->whereKey($commerce->id)->lockForUpdate()->first();
                }

                $fulfilment = $assessment['fulfilment'];
                if ($fulfilment !== null) {
                    $fulfilment = $fulfilment->newQuery()->whereKey($fulfilment->id)->lockForUpdate()->first();
                }

                if ($lockedCommerce !== null && $lockedCommerce->hardware_fulfilment_blocked_at !== null) {
                    throw ValidationException::withMessages([
                        'commerce_order' => 'Fulfilment is already blocked for this order.',
                    ]);
                }

                $reassess = $this->eligibility->assess($lockedOrder);
                if (! $reassess['eligible']) {
                    throw ValidationException::withMessages([
                        'order_id' => 'Eligibility changed during submission.',
                        'eligibility_code' => $reassess['code']?->value,
                    ]);
                }

                if ($reassess['phase'] === 'A' && HardwareFulfilment::query()->where('support_order_id', $lockedOrder->id)->exists()) {
                    throw ValidationException::withMessages([
                        'fulfilment' => 'Hardware fulfilment appeared during cancellation. Retry as State B.',
                        'eligibility_code' => PreShipmentEligibilityFailureCode::AmbiguousState->value,
                    ]);
                }

                $reference = $this->nextReferenceNo();
                $family = PreShipmentCancellationOrderFamily::fromSourceId($lockedOrder->order_id)
                    ?? PreShipmentCancellationOrderFamily::Rbp;

                $request = PreShipmentCancellationRequest::query()->create([
                    'reference_no' => $reference,
                    'order_id' => $lockedOrder->id,
                    'commerce_order_id' => $lockedCommerce?->id,
                    'hardware_fulfilment_id' => $fulfilment?->id,
                    'source_id' => strtoupper((string) $lockedOrder->order_id),
                    'order_family' => $family,
                    'status' => PreShipmentCancellationStatus::Submitted,
                    'blocker_reason' => $payload['blocker_reason'] ?? null,
                    'cancellation_reason' => trim((string) $payload['cancellation_reason']),
                    'submitted_by_user_id' => $actor->id,
                    'shipment_verification' => PreShipmentCancellationShipmentVerification::tryFrom(
                        (string) ($payload['shipment_verification'] ?? PreShipmentCancellationShipmentVerification::ConfirmedUnshipped->value)
                    ) ?? PreShipmentCancellationShipmentVerification::ConfirmedUnshipped,
                    'order_total_amount' => $breakdown->orderTotal,
                    'wallet_tender_amount' => $breakdown->walletTender,
                    'external_tender_amount' => $breakdown->externalTender,
                    'already_refunded_amount' => $breakdown->alreadyRefunded,
                    'maximum_refundable_amount' => $breakdown->maximumRefundable,
                    'requested_refund_amount' => $requested,
                    'split_tender' => $breakdown->splitTender,
                    'execution_hold' => $breakdown->splitTender,
                    'execution_hold_reason' => $breakdown->splitTender
                        ? 'Split-tender Phase 2 requires manual Finance review and execution.'
                        : null,
                    'idempotency_key' => (string) $payload['idempotency_key'],
                    'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Pending,
                ]);

                $committedAt = now();
                if ($lockedCommerce !== null) {
                    $lockedCommerce->forceFill([
                        'hardware_fulfilment_blocked_at' => $committedAt,
                        'pre_shipment_cancellation_request_id' => $request->id,
                    ])->save();
                }

                if ($fulfilment !== null && $reassess['phase'] === 'B') {
                    $this->fulfilmentWorkflow->transition(
                        fulfilment: $fulfilment,
                        to: HardwareFulfilmentState::CancelledPreShipment,
                        actorType: 'user',
                        actorId: $actor->id,
                        payload: [
                            'reason' => 'pre_shipment_cancellation',
                            'pre_shipment_cancellation_request_id' => $request->id,
                        ],
                    );
                }

                $request->forceFill([
                    'committed_at' => $committedAt,
                    'ingestion_block_version' => 1,
                ])->save();

                $request = $this->incidentService->closeForCancellation($request->fresh() ?? $request, $actor);

                $resolution = $this->invoiceResolver->resolveForOrder($lockedOrder);
                $invoice = $resolution->invoice;
                if ($invoice !== null) {
                    $request->forceFill([
                        'status' => PreShipmentCancellationStatus::HeldExistingInvoice,
                        'recorded_tax_statutory_invoice_id' => $invoice->id,
                    ])->save();
                } elseif ($request->status === PreShipmentCancellationStatus::HeldServiceCase) {
                    // held by incident service
                } elseif ($breakdown->splitTender) {
                    $request->forceFill([
                        'status' => PreShipmentCancellationStatus::FinanceReview,
                    ])->save();
                } else {
                    $request->forceFill([
                        'status' => PreShipmentCancellationStatus::FinanceApproved,
                        'reviewed_by_user_id' => $actor->id,
                    ])->save();
                }

                return $request->fresh() ?? $request;
            });

            $this->dispatchIncidentRetryIfNeeded($case, $actor->id);
            $this->refundAutomation->dispatchAfterCommit($case, $actor->id);

            return $case;
        } catch (UniqueConstraintViolationException) {
            $prior = PreShipmentCancellationRequest::query()
                ->where('idempotency_key', (string) $payload['idempotency_key'])
                ->first();
            if ($prior !== null) {
                return $prior;
            }

            throw ValidationException::withMessages([
                'idempotency_key' => 'A cancellation case already exists for this order.',
            ]);
        }
    }

    private function dispatchIncidentRetryIfNeeded(PreShipmentCancellationRequest $case, int $actorUserId): void
    {
        if ($case->service_case_sync_status === PreShipmentCancellationServiceCaseSyncStatus::Succeeded
            || $case->service_case_sync_status === PreShipmentCancellationServiceCaseSyncStatus::NotApplicable) {
            return;
        }

        if ($case->service_case_sync_status === PreShipmentCancellationServiceCaseSyncStatus::Failed
            || $case->service_case_sync_status === PreShipmentCancellationServiceCaseSyncStatus::Pending) {
            ClosePreShipmentCancellationIncidentJob::dispatch($case->id, $actorUserId);
        }
    }

    private function nextReferenceNo(): string
    {
        $date = now()->format('Ymd');
        $count = PreShipmentCancellationRequest::query()
            ->where('reference_no', 'like', 'PSC-'.$date.'-%')
            ->count() + 1;

        return sprintf('PSC-%s-%04d', $date, $count);
    }
}
