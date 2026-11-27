<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePreShipmentCancellationRequest;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Services\Orders\OrderPaymentBreakdownResolver;
use App\Services\PreShipmentCancellation\PreShipmentCancellationEligibilityService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationSubmitService;
use App\Support\PreShipmentCancellation\PreShipmentCancellationAccess;
use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PreShipmentCancellationRequestController extends Controller
{
    public function __construct(
        private readonly PreShipmentCancellationEligibilityService $eligibility,
        private readonly OrderPaymentBreakdownResolver $paymentBreakdown,
        private readonly PreShipmentCancellationSubmitService $submitService,
    ) {}

    public function create(Order $order): View
    {
        $this->authorize('submit', PreShipmentCancellationRequest::class);

        $assessment = $this->eligibility->assess($order);
        $breakdown = null;
        $breakdownError = null;

        if ($assessment['eligible'] && $assessment['commerce'] !== null) {
            try {
                $breakdown = $this->paymentBreakdown->resolve($order, $assessment['commerce']);
            } catch (\Throwable $throwable) {
                $breakdownError = $throwable->getMessage();
            }
        }

        return view('pre-shipment-cancellations.create', [
            'order' => $order,
            'assessment' => $assessment,
            'paymentBreakdown' => $breakdown,
            'breakdownError' => $breakdownError,
            'canSubmit' => $assessment['eligible'] && $breakdown !== null,
        ]);
    }

    public function preflight(Order $order): JsonResponse
    {
        $this->authorize('submit', PreShipmentCancellationRequest::class);

        $assessment = $this->eligibility->assess($order);
        $breakdown = null;
        $breakdownError = null;

        if ($assessment['eligible'] && $assessment['commerce'] !== null) {
            try {
                $breakdown = $this->paymentBreakdown->resolve($order, $assessment['commerce']);
            } catch (\Throwable $throwable) {
                $breakdownError = $throwable->getMessage();
            }
        }

        $canSubmit = $assessment['eligible'] && $breakdown !== null;

        return response()->json([
            'order' => [
                'id' => $order->id,
                'order_id' => $order->order_id,
            ],
            'eligible' => $assessment['eligible'],
            'eligibility_code' => $assessment['code']?->value,
            'phase' => $assessment['phase'],
            'can_submit' => $canSubmit,
            'breakdown_error' => $breakdownError,
            'payment_breakdown' => $breakdown === null ? null : [
                'order_total' => $breakdown->orderTotal,
                'external_tender' => $breakdown->externalTender,
                'wallet_tender' => $breakdown->walletTender,
                'already_refunded' => $breakdown->alreadyRefunded,
                'maximum_refundable' => $breakdown->maximumRefundable,
                'split_tender' => $breakdown->splitTender,
            ],
            'store_url' => route('orders.pre-shipment-cancellations.store', $order),
        ]);
    }

    public function store(StorePreShipmentCancellationRequest $request, Order $order): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $idempotencyKey = (string) ($validated['idempotency_key'] ?? sprintf(
            'psc-submit:%d:%s',
            $order->id,
            hash('sha256', $request->user()->id.'|'.($validated['cancellation_reason'] ?? ''))
        ));

        $blockerReason = $validated['blocker_reason']
            ?? PreShipmentCancellationOperatorMetadata::encode(
                $validated['refund_preference'] ?? null,
                $validated['operator_notes'] ?? null,
            );

        $case = $this->submitService->submit($request->user(), $order, [
            'cancellation_reason' => $validated['cancellation_reason'],
            'blocker_reason' => $blockerReason,
            'shipment_verification' => $validated['shipment_verification'] ?? null,
            'requested_refund_amount' => $validated['requested_refund_amount'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Pre-shipment cancellation '.$case->reference_no.' submitted.',
                'case_id' => $case->id,
                'reference_no' => $case->reference_no,
                'show_url' => route('pre-shipment-cancellations.show', $case),
            ]);
        }

        return redirect()
            ->route('pre-shipment-cancellations.show', $case)
            ->with('status', 'Pre-shipment cancellation '.$case->reference_no.' submitted.');
    }

    public function show(PreShipmentCancellationRequest $preShipmentCancellation): View
    {
        $this->authorize('view', $preShipmentCancellation);

        $preShipmentCancellation->load(['order', 'refundRequest', 'submittedBy', 'reviewedBy', 'hardwareFulfilment']);

        $breakdown = null;
        if ($preShipmentCancellation->order !== null) {
            try {
                $breakdown = $this->paymentBreakdown->resolve(
                    $preShipmentCancellation->order,
                    $preShipmentCancellation->commerceOrder,
                );
            } catch (\Throwable) {
                $breakdown = null;
            }
        }

        $user = auth()->user();

        return view('pre-shipment-cancellations.show', [
            'case' => $preShipmentCancellation,
            'paymentBreakdown' => $breakdown,
            'canRetryIncident' => $user?->can('retryIncident', $preShipmentCancellation) ?? false,
            'canApprove' => $user?->can('approve', $preShipmentCancellation) ?? false,
            'canReject' => $user?->can('reject', $preShipmentCancellation) ?? false,
            'canLinkRefund' => $user?->can('linkRefund', $preShipmentCancellation) ?? false,
            'centralNavVisible' => PreShipmentCancellationAccess::canViewCentral($user),
        ]);
    }
}
