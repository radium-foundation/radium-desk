<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\Order;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentFoundationService
{
    public function __construct(
        private readonly HardwareFulfilmentPaymentCorrelationService $paymentCorrelation,
        private readonly HardwareFulfilmentWorkflowService $workflow,
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

            return $this->attemptReady($existing);
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

                return $this->attemptReady($raced);
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

        return $this->attemptReady($fulfilment);
    }

    /**
     * Open or return the HF for an already-persisted paid Commerce order.
     * Does not ingest a Box handoff and does not create a second Commerce order.
     */
    public function ensureIngestedFromExistingCommerce(CommerceOrder $order): HardwareFulfilment
    {
        $order->loadMissing('items');
        app(HardwareRecoveredFulfilmentAuthorization::class)->requireAuthorized($order);

        $existing = HardwareFulfilment::query()
            ->where('commerce_order_id', $order->id)
            ->first();
        if ($existing !== null) {
            $this->fillMissingCorrelationFromOrder($existing, $order);
            $this->paymentCorrelation->attachPendingEvidence($existing);

            return $this->attemptReady($existing);
        }

        $bySource = HardwareFulfilment::query()
            ->whereRaw('UPPER(source_id) = ?', [strtoupper((string) $order->source_id)])
            ->get();
        if ($bySource->count() > 1) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Multiple hardware fulfilments match this source id. Isolated fulfilment fails closed.',
            ]);
        }
        if ($bySource->count() === 1) {
            $found = $bySource->first();
            if ((int) $found->commerce_order_id !== (int) $order->id) {
                throw ValidationException::withMessages([
                    'fulfilment' => 'An existing hardware fulfilment does not belong to this commerce order.',
                ]);
            }
            $this->fillMissingCorrelationFromOrder($found, $order);
            $this->paymentCorrelation->attachPendingEvidence($found);

            return $this->attemptReady($found);
        }

        $link = $this->resolveCashfreeLinkFromOrder($order);
        $this->persistSupportOrderId($order, $link['support_order_id']);
        $idempotencyKey = (string) $order->idempotency_key;
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'fulfilment' => 'Existing commerce order is missing its idempotency key.',
            ]);
        }

        try {
            $fulfilment = HardwareFulfilment::query()->create([
                'commerce_order_id' => $order->id,
                'channel' => $order->channel,
                'source_type' => (string) $order->source_type,
                'source_id' => (string) $order->source_id,
                'idempotency_key' => $idempotencyKey,
                'state' => HardwareFulfilmentState::Ingested,
                'support_order_id' => $link['support_order_id'] ?? $order->support_order_id,
                'cashfree_payment_id' => $link['cashfree_payment_id'],
                'payment_reference' => $order->payment_reference,
                'retry_count' => 0,
                'ingested_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $raced = HardwareFulfilment::query()
                ->where('commerce_order_id', $order->id)
                ->first();
            if ($raced === null) {
                throw ValidationException::withMessages([
                    'fulfilment' => 'Hardware fulfilment create raced and no row was found.',
                ]);
            }
            $this->paymentCorrelation->attachPendingEvidence($raced);

            return $this->attemptReady($raced);
        }

        HardwareFulfilmentEvent::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'from_state' => null,
            'to_state' => HardwareFulfilmentState::Ingested,
            'actor_type' => 'system',
            'payload' => [
                'reason' => 'recovered_commerce_ingest',
                'source_id' => $order->source_id,
                'commerce_order_id' => $order->id,
            ],
            'created_at' => now(),
        ]);

        $this->paymentCorrelation->attachPendingEvidence($fulfilment);

        return $this->attemptReady($fulfilment);
    }

    /**
     * Reuse the single readiness gate. Expected eligibility refusals keep INGESTED.
     * Unexpected failures propagate so the surrounding ingest transaction can roll back.
     */
    private function attemptReady(?HardwareFulfilment $fulfilment): ?HardwareFulfilment
    {
        if ($fulfilment === null) {
            return null;
        }

        $fresh = $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
        if ($fresh->state !== HardwareFulfilmentState::Ingested) {
            return $fresh;
        }

        try {
            return $this->workflow->markReady($fresh);
        } catch (ValidationException $exception) {
            Log::notice('Hardware fulfilment remained ingested after readiness gate', [
                'hardware_fulfilment_id' => $fresh->id,
                'source_id' => $fresh->source_id,
                'errors' => $exception->errors(),
            ]);

            return $fresh->fresh() ?? $fresh;
        }
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

    /**
     * @return array{support_order_id: ?int, cashfree_payment_id: ?string}
     */
    public function resolveCashfreeLinkFromOrder(CommerceOrder $order): array
    {
        $deskOrder = Order::query()
            ->where('order_id', $order->source_id)
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
            'support_order_id' => $order->support_order_id !== null ? (int) $order->support_order_id : null,
            'cashfree_payment_id' => null,
        ];
    }

    private function fillMissingCorrelationFromOrder(HardwareFulfilment $fulfilment, CommerceOrder $order): void
    {
        $link = $this->resolveCashfreeLinkFromOrder($order);
        $updates = [];

        if ($fulfilment->support_order_id === null && $link['support_order_id'] !== null) {
            $updates['support_order_id'] = $link['support_order_id'];
        }
        if ($fulfilment->cashfree_payment_id === null && $link['cashfree_payment_id'] !== null) {
            $updates['cashfree_payment_id'] = $link['cashfree_payment_id'];
        }
        if ($fulfilment->payment_reference === null && filled($order->payment_reference)) {
            $updates['payment_reference'] = $order->payment_reference;
        }

        if ($updates !== []) {
            $fulfilment->forceFill($updates)->save();
        }

        $this->persistSupportOrderId($order, $link['support_order_id'] ?? $fulfilment->support_order_id);
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
