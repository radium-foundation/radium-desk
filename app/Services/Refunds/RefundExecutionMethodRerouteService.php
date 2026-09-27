<?php

namespace App\Services\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundExecutionMethodRerouteService
{
    public function __construct(
        private readonly WalletRefundDestinationResolver $walletDestinations,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function canReroute(RefundRequest $refund): bool
    {
        try {
            $this->assertRerouteEligible($refund->fresh(['order', 'incident']) ?? $refund);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function reroute(
        RefundRequest $refund,
        User $actor,
        string $reason,
        Request $request,
    ): RefundRequest {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reroute_reason' => 'A re-route reason is required.',
            ]);
        }

        return DB::transaction(function () use ($refund, $actor, $reason, $request): RefundRequest {
            /** @var RefundRequest $locked */
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($refund->id);
            $locked->loadMissing(['order', 'incident']);

            if ($locked->approved_refund_method === ApprovedRefundMethod::Cashfree) {
                return $locked;
            }

            $this->assertRerouteEligible($locked);

            $oldValues = $this->rerouteSnapshot($locked);
            $targetMethod = ApprovedRefundMethod::Cashfree;

            $locked->update([
                'approved_refund_method' => $targetMethod,
            ]);

            $fresh = $locked->fresh(['order', 'incident']) ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'refund.execution_method_rerouted',
                auditable: $fresh,
                oldValues: $oldValues,
                newValues: $this->rerouteSnapshot($fresh, $reason),
                request: $request,
            );

            return $fresh;
        });
    }

    private function assertRerouteEligible(RefundRequest $refund): void
    {
        if ($refund->status !== RefundStatus::PendingExecution) {
            throw ValidationException::withMessages([
                'refund' => 'Only refunds pending execution can be re-routed to another payout method.',
            ]);
        }

        if ($refund->executed_at !== null) {
            throw ValidationException::withMessages([
                'refund' => 'Executed refunds cannot be re-routed.',
            ]);
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            throw ValidationException::withMessages([
                'refund' => 'Only wallet-approved refunds can be re-routed to Cashfree.',
            ]);
        }

        $orderId = $refund->order?->order_id;
        if (! is_string($orderId) || trim($orderId) === '') {
            throw ValidationException::withMessages([
                'refund' => 'Refund order id is required before execution method can be re-routed.',
            ]);
        }

        if ($this->walletDestinations->supportsAutomatedWalletCredit($orderId)) {
            throw ValidationException::withMessages([
                'refund' => 'This order supports automated wallet credit. Re-route is only available when no wallet destination is configured.',
            ]);
        }

        $order = $refund->order;
        if (! $order instanceof Order) {
            throw ValidationException::withMessages([
                'refund' => 'Refund order is required before execution method can be re-routed.',
            ]);
        }

        if (! $this->hasOriginalPaymentEvidence($order)) {
            throw ValidationException::withMessages([
                'refund' => 'Original payment evidence is required before re-routing to Cashfree.',
            ]);
        }
    }

    private function hasOriginalPaymentEvidence(Order $order): bool
    {
        if ((float) ($order->payment_amount ?? 0) <= 0) {
            return false;
        }

        return $this->resolveOriginalPaymentReference($order) !== null;
    }

    private function resolveOriginalPaymentReference(Order $order): ?string
    {
        foreach ([
            $order->cashfree_payment_id,
            $order->gateway_payment_id,
            $order->transaction_id,
            $order->bank_reference,
        ] as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                return trim($reference);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rerouteSnapshot(RefundRequest $refund, ?string $reason = null): array
    {
        $order = $refund->order;

        return [
            'refund_id' => $refund->id,
            'reference_no' => $refund->reference_no,
            'status' => $refund->status?->value,
            'approved_refund_method' => $refund->approved_refund_method?->value,
            'refund_amount' => $refund->refund_amount ?? $refund->amount,
            'order_id' => $order?->order_id,
            'incident_reference_no' => $refund->incident?->reference_no,
            'customer_preferred_method' => $refund->customer_preferred_method?->value,
            'original_payment_reference' => $order instanceof Order
                ? $this->resolveOriginalPaymentReference($order)
                : null,
            'reroute_reason' => $reason,
        ];
    }
}
