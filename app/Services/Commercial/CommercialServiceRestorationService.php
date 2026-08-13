<?php

namespace App\Services\Commercial;

use App\Enums\ApprovedRefundMethod;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\CommercialServiceRestoration;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardBroadcastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only attestation that Finance reversed an external wallet refund
 * so commercial work may resume on the original paid order.
 *
 * Never mutates refund_requests, payments, Cashfree, or wallet systems.
 */
class CommercialServiceRestorationService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly DashboardSnapshotStore $dashboardSnapshotStore,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function activeFor(Order $order, RefundRequest $refund): ?CommercialServiceRestoration
    {
        return CommercialServiceRestoration::query()
            ->with(['recordedBy', 'verifiedBy'])
            ->active()
            ->where('order_id', $order->id)
            ->where('refund_request_id', $refund->id)
            ->latest('id')
            ->first();
    }

    public function activeForRefundId(int $orderId, int $refundRequestId): ?CommercialServiceRestoration
    {
        return CommercialServiceRestoration::query()
            ->with(['recordedBy', 'verifiedBy'])
            ->active()
            ->where('order_id', $orderId)
            ->where('refund_request_id', $refundRequestId)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array{
     *     finance_verified: bool,
     *     wallet_reversed_externally: bool,
     *     wallet_reversal_reference?: string|null,
     *     finance_note?: string|null,
     * }  $data
     */
    public function restore(Order $order, RefundRequest $refund, User $actor, array $data): CommercialServiceRestoration
    {
        $this->assertEligibleRefund($order, $refund);

        if (! (bool) ($data['finance_verified'] ?? false) || ! (bool) ($data['wallet_reversed_externally'] ?? false)) {
            throw ValidationException::withMessages([
                'finance_verified' => 'Finance Verified and Wallet Reversed Externally are both required.',
            ]);
        }

        $reference = trim((string) ($data['wallet_reversal_reference'] ?? ''));
        if ($reference === '') {
            throw ValidationException::withMessages([
                'wallet_reversal_reference' => 'Wallet reversal reference is required.',
            ]);
        }

        return DB::transaction(function () use ($order, $refund, $actor, $data, $reference): CommercialServiceRestoration {
            CommercialServiceRestoration::query()
                ->where('order_id', $order->id)
                ->where('refund_request_id', $refund->id)
                ->lockForUpdate()
                ->get();

            $existing = $this->activeFor($order, $refund);
            if ($existing instanceof CommercialServiceRestoration) {
                throw ValidationException::withMessages([
                    'refund_request_id' => 'An active commercial service restoration already exists for this refund.',
                ]);
            }

            $now = now();
            $restoration = CommercialServiceRestoration::query()->create([
                'order_id' => $order->id,
                'refund_request_id' => $refund->id,
                'finance_verified' => true,
                'wallet_reversed_externally' => true,
                'wallet_reversal_reference' => $reference,
                'finance_note' => filled($data['finance_note'] ?? null) ? trim((string) $data['finance_note']) : null,
                'verified_by_user_id' => $actor->id,
                'verified_at' => $now,
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => $now,
            ]);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'commercial.service_restored',
                auditable: $restoration,
                oldValues: null,
                newValues: [
                    'order_id' => $order->id,
                    'order_number' => $order->order_id,
                    'refund_request_id' => $refund->id,
                    'refund_reference' => $refund->reference_no,
                    'wallet_reversal_reference' => $restoration->wallet_reversal_reference,
                    'finance_note' => $restoration->finance_note,
                    'finance_verified' => true,
                    'wallet_reversed_externally' => true,
                    'recorded_at' => $restoration->recorded_at?->toIso8601String(),
                ],
            );

            $this->syncDashboardQueueMembershipAfterCommercialChange($order, $actor);

            return $restoration->fresh(['order', 'refundRequest', 'recordedBy', 'verifiedBy']) ?? $restoration;
        });
    }

    public function revoke(CommercialServiceRestoration $restoration, User $actor): CommercialServiceRestoration
    {
        if (! $restoration->isActive()) {
            throw ValidationException::withMessages([
                'restoration' => 'This commercial service restoration is already revoked.',
            ]);
        }

        return DB::transaction(function () use ($restoration, $actor): CommercialServiceRestoration {
            /** @var CommercialServiceRestoration $locked */
            $locked = CommercialServiceRestoration::query()
                ->whereKey($restoration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'restoration' => 'This commercial service restoration is already revoked.',
                ]);
            }

            $locked->update(['revoked_at' => now()]);
            $fresh = $locked->fresh(['order', 'refundRequest']) ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'commercial.service_restoration_revoked',
                auditable: $fresh,
                oldValues: [
                    'revoked_at' => null,
                ],
                newValues: [
                    'order_id' => $fresh->order_id,
                    'order_number' => $fresh->order?->order_id,
                    'refund_request_id' => $fresh->refund_request_id,
                    'refund_reference' => $fresh->refundRequest?->reference_no,
                    'wallet_reversal_reference' => $fresh->wallet_reversal_reference,
                    'revoked_at' => $fresh->revoked_at?->toIso8601String(),
                ],
            );

            $order = $fresh->order;

            if ($order instanceof Order) {
                $this->syncDashboardQueueMembershipAfterCommercialChange($order, $actor);
            }

            return $fresh;
        });
    }

    private function syncDashboardQueueMembershipAfterCommercialChange(Order $order, User $actor): void
    {
        $this->dashboardSnapshotStore->forget();

        $incidents = Incident::query()
            ->where('order_record_id', $order->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->get();

        foreach ($incidents as $incident) {
            $this->dashboardBroadcastService->serviceCaseQueueMembershipChanged(
                $incident->fresh([
                    'order.transactionAssigner',
                    'creator',
                    'assignee.roles',
                    'activeWaitingState',
                    'activeBusinessHold',
                    'supportAppointments',
                ]),
                $actor,
            );
        }
    }

    private function assertEligibleRefund(Order $order, RefundRequest $refund): void
    {
        if ((int) $refund->order_id !== (int) $order->id) {
            throw ValidationException::withMessages([
                'refund_request_id' => 'Refund does not belong to this order.',
            ]);
        }

        $status = $refund->status;
        if (! $status instanceof RefundStatus || ! $status->isTerminalSuccess()) {
            throw ValidationException::withMessages([
                'refund_request_id' => 'Only a completed wallet refund can be restored for commercial service.',
            ]);
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            throw ValidationException::withMessages([
                'refund_request_id' => 'Commercial service restoration applies only to wallet refunds.',
            ]);
        }
    }
}
