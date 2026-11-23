<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePreShipmentCancellationRequest;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Services\Orders\OrderPaymentBreakdownResolver;
use App\Services\PreShipmentCancellation\PreShipmentCancellationEligibilityService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationSubmitService;
use App\Support\PreShipmentCancellation\PreShipmentCancellationAccess;
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

    public function store(StorePreShipmentCancellationRequest $request, Order $order): RedirectResponse
    {
        $validated = $request->validated();
        $idempotencyKey = (string) ($validated['idempotency_key'] ?? sprintf(
            'psc-submit:%d:%s',
            $order->id,
            hash('sha256', $request->user()->id.'|'.($validated['cancellation_reason'] ?? ''))
        ));

        $case = $this->submitService->submit($request->user(), $order, [
            'cancellation_reason' => $validated['cancellation_reason'],
            'blocker_reason' => $validated['blocker_reason'] ?? null,
            'shipment_verification' => $validated['shipment_verification'] ?? null,
            'requested_refund_amount' => $validated['requested_refund_amount'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);

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
