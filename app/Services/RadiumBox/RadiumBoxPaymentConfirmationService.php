<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\RadiumBoxHandoffReconciliationResult;
use App\Data\RadiumBox\RadiumBoxPaymentConfirmationResult;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Support\BusinessOrderId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RadiumBoxPaymentConfirmationService
{
    public function __construct(
        private readonly RadiumBoxPaymentConfirmationClient $client,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function requiresBoxPaymentConfirmation(Order $order): bool
    {
        if (! $order->isCashfreeVerified()) {
            return false;
        }

        if (! config('radiumbox.payment_confirm.enabled', true)) {
            return false;
        }

        $parsed = BusinessOrderId::parse($order->order_id);
        if ($parsed === null) {
            return false;
        }

        return $parsed['owner'] === 'radiumbox.com'
            && $parsed['hardware'] === true;
    }

    public function missingCommerceHandoff(Order $order): bool
    {
        if (! $this->requiresBoxPaymentConfirmation($order)) {
            return false;
        }

        return ! $this->hasCommerceFor($order);
    }

    public function confirmForBusinessOrderId(string $businessOrderId, bool $dryRun = false): RadiumBoxPaymentConfirmationResult
    {
        $normalized = strtoupper(trim($businessOrderId));
        if ($normalized === '') {
            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'rejected',
                errorMessage: 'Business order id is required.',
            );
        }

        $order = Order::query()
            ->whereRaw('UPPER(order_id) = ?', [$normalized])
            ->first();

        if ($order === null) {
            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'not_found',
                gatewayOrderId: $normalized,
                errorMessage: 'Desk order not found for business order id.',
            );
        }

        return $this->confirmForOrder($order, $dryRun);
    }

    public function confirmForOrder(Order $order, bool $dryRun = false): RadiumBoxPaymentConfirmationResult
    {
        if (! $this->requiresBoxPaymentConfirmation($order)) {
            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'skipped',
                errorMessage: 'Order does not require Box payment confirmation.',
            );
        }

        // Box Cashfree sessions use the business order code (RBP*/RDE*) as cf_order_id.
        // Desk webhooks store Cashfree's numeric gateway_order_id separately; sending that
        // to Box confirm-payment breaks resolvePayable() and caused RBP94 not_found.
        $gatewayOrderId = trim((string) $order->order_id);
        $paymentId = trim((string) ($order->cashfree_payment_id ?? ''));
        $paymentId = $paymentId !== '' ? $paymentId : null;

        $result = $this->client->confirmPayment($gatewayOrderId, $paymentId, $dryRun);

        if ($result->ok && ! $dryRun) {
            $this->recordConfirmationOutcome($order, $result);
        } elseif ($result->retriable) {
            $this->syncStore->markReconciliationRequired(
                $order->id,
                $result->errorMessage ?? 'Box payment confirmation is pending retry.',
            );
        } elseif (! $result->ok && ! $dryRun) {
            $this->syncStore->markHandoffFailed(
                $order->id,
                $result->errorMessage ?? 'Box payment confirmation failed.',
            );
        }

        Log::info('[RadiumBox payment confirm] Desk invoked Box confirm-payment.', [
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'gateway_order_id' => $gatewayOrderId,
            'status' => $result->status,
            'ok' => $result->ok,
            'first_paid' => $result->firstPaid,
            'handoff_enqueued' => $result->handoffEnqueued(),
            'dry_run' => $dryRun,
        ]);

        return $result;
    }

    public function reconcile(?int $limit = null, bool $dryRun = false): RadiumBoxHandoffReconciliationResult
    {
        $limit ??= (int) config('radiumbox.handoff_reconciliation.schedule_limit', 25);
        $slaMinutes = max(1, (int) config('radiumbox.handoff_reconciliation.sla_minutes', 15));
        $scanned = 0;
        $recovered = 0;
        $skipped = 0;
        $recoveredOrderIds = [];
        $skippedOrderIds = [];

        $this->eligibleOrdersQuery($slaMinutes)
            ->orderBy('id')
            ->chunkById(25, function ($orders) use (
                $limit,
                $dryRun,
                &$scanned,
                &$recovered,
                &$skipped,
                &$recoveredOrderIds,
                &$skippedOrderIds,
            ): bool {
                foreach ($orders as $order) {
                    if ($recovered >= $limit) {
                        return false;
                    }

                    $scanned++;

                    if (! $this->missingCommerceHandoff($order)) {
                        $skipped++;
                        $skippedOrderIds[] = $order->id;

                        continue;
                    }

                    if ($dryRun) {
                        $recovered++;
                        $recoveredOrderIds[] = $order->id;

                        continue;
                    }

                    $result = $this->confirmForOrder($order);
                    if ($result->ok) {
                        $recovered++;
                        $recoveredOrderIds[] = $order->id;
                    } else {
                        $skipped++;
                        $skippedOrderIds[] = $order->id;
                    }
                }

                return $recovered < $limit;
            });

        if ($scanned > 0) {
            Log::info('[RadiumBox handoff reconcile] Completed scan.', [
                'scanned' => $scanned,
                'recovered' => $recovered,
                'skipped' => $skipped,
                'dry_run' => $dryRun,
                'sla_minutes' => $slaMinutes,
            ]);
        }

        return new RadiumBoxHandoffReconciliationResult(
            scanned: $scanned,
            recovered: $recovered,
            skipped: $skipped,
            recoveredOrderIds: $recoveredOrderIds,
            skippedOrderIds: $skippedOrderIds,
        );
    }

    /**
     * @return Builder<Order>
     */
    private function eligibleOrdersQuery(int $slaMinutes): Builder
    {
        $cutoff = Carbon::now()->subMinutes($slaMinutes);

        return Order::query()
            ->cashfreeVerified()
            ->where(function (Builder $query): void {
                $query->where('order_id', 'like', 'RDE%')
                    ->orWhere('order_id', 'like', 'RBP%');
            })
            ->where('created_at', '<=', $cutoff)
            ->whereIn('radiumbox_sync_status', [
                RadiumBoxEnrichmentSyncStatus::HandoffPending->value,
                RadiumBoxEnrichmentSyncStatus::ReconciliationRequired->value,
                RadiumBoxEnrichmentSyncStatus::HandoffFailed->value,
                RadiumBoxEnrichmentSyncStatus::Synced->value,
                RadiumBoxEnrichmentSyncStatus::Failed->value,
                RadiumBoxEnrichmentSyncStatus::Pending->value,
                RadiumBoxEnrichmentSyncStatus::NotSynced->value,
            ])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('commerce_orders')
                    ->whereColumn('commerce_orders.source_id', 'orders.order_id');
            });
    }

    private function hasCommerceFor(Order $order): bool
    {
        return CommerceOrder::query()
            ->where('source_id', $order->order_id)
            ->exists();
    }

    private function recordConfirmationOutcome(Order $order, RadiumBoxPaymentConfirmationResult $result): void
    {
        if ($this->hasCommerceFor($order)) {
            $this->syncStore->markSynced($order->id, [
                'box_payment_confirmed' => true,
                'box_confirm_status' => $result->status,
            ]);

            return;
        }

        if ($result->handoffEnqueued()) {
            $this->syncStore->markHandoffPending($order->id, [
                'box_payment_confirmed' => true,
                'box_confirm_status' => $result->status,
                'handoff_id' => $result->handoff['id'] ?? null,
            ]);

            return;
        }

        $this->syncStore->markReconciliationRequired(
            $order->id,
            'Box payment confirmed but handoff was not enqueued.',
        );
    }
}
