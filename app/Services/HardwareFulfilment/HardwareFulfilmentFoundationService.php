<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\Order;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use Illuminate\Database\UniqueConstraintViolationException;

class HardwareFulfilmentFoundationService
{
    public function __construct(
        private readonly HardwareFulfilmentPaymentCorrelationService $paymentCorrelation,
    ) {}

    public function ensureIngested(CommerceOrder $order, ChannelOrderIngestRequest $request): ?HardwareFulfilment
    {
        if (! HardwareFulfilmentEligibility::shouldOpenRecord($request)) {
            return HardwareFulfilment::query()
                ->where('commerce_order_id', $order->id)
                ->first();
        }

        $existing = HardwareFulfilment::query()
            ->where('commerce_order_id', $order->id)
            ->first();
        if ($existing !== null) {
            $this->fillMissingCorrelation($existing, $order, $request);
            $this->paymentCorrelation->attachPendingEvidence($existing);

            return $existing;
        }

        $link = $this->resolveCashfreeLink($request);
        $this->persistSupportOrderId($order, $link['support_order_id']);

        try {
            $fulfilment = HardwareFulfilment::query()->create([
                'commerce_order_id' => $order->id,
                'channel' => $request->channel,
                'source_type' => $request->sourceType->value,
                'source_id' => $request->sourceId,
                'idempotency_key' => $request->idempotencyKey(),
                'state' => HardwareFulfilmentState::Ingested,
                'support_order_id' => $link['support_order_id'] ?? $order->support_order_id,
                'cashfree_payment_id' => $link['cashfree_payment_id'],
                'payment_reference' => $request->paymentReference,
                'retry_count' => 0,
                'ingested_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $raced = HardwareFulfilment::query()
                ->where('commerce_order_id', $order->id)
                ->first();
            if ($raced !== null) {
                $this->paymentCorrelation->attachPendingEvidence($raced);
            }

            return $raced;
        }

        HardwareFulfilmentEvent::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'from_state' => null,
            'to_state' => HardwareFulfilmentState::Ingested,
            'actor_type' => 'system',
            'payload' => [
                'reason' => 'channel_ingest',
                'source_id' => $request->sourceId,
            ],
            'created_at' => now(),
        ]);

        $this->paymentCorrelation->attachPendingEvidence($fulfilment);

        return $fulfilment;
    }

    /**
     * Cashfree / Desk `orders` correlation only. Never a statutory identity.
     *
     * @return array{support_order_id: ?int, cashfree_payment_id: ?string}
     */
    public function resolveCashfreeLink(ChannelOrderIngestRequest $request): array
    {
        $deskOrder = Order::query()
            ->where('order_id', $request->sourceId)
            ->first();

        if ($deskOrder !== null) {
            return [
                'support_order_id' => (int) $deskOrder->id,
                'cashfree_payment_id' => filled($deskOrder->cashfree_payment_id)
                    ? (string) $deskOrder->cashfree_payment_id
                    : null,
            ];
        }

        return [
            'support_order_id' => $request->supportOrderId,
            'cashfree_payment_id' => null,
        ];
    }

    private function fillMissingCorrelation(
        HardwareFulfilment $fulfilment,
        CommerceOrder $order,
        ChannelOrderIngestRequest $request,
    ): void {
        $link = $this->resolveCashfreeLink($request);
        $updates = [];

        if ($fulfilment->support_order_id === null && $link['support_order_id'] !== null) {
            $updates['support_order_id'] = $link['support_order_id'];
        }
        if ($fulfilment->cashfree_payment_id === null && $link['cashfree_payment_id'] !== null) {
            $updates['cashfree_payment_id'] = $link['cashfree_payment_id'];
        }
        if ($fulfilment->payment_reference === null && $request->paymentReference !== null) {
            $updates['payment_reference'] = $request->paymentReference;
        }

        if ($updates !== []) {
            $fulfilment->forceFill($updates)->save();
        }

        $this->persistSupportOrderId($order, $link['support_order_id'] ?? $fulfilment->support_order_id);
    }

    private function persistSupportOrderId(CommerceOrder $order, ?int $supportOrderId): void
    {
        if ($supportOrderId === null || $order->support_order_id !== null) {
            return;
        }

        $order->forceFill(['support_order_id' => $supportOrderId])->save();
    }
}
