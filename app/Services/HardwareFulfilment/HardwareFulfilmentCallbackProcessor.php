<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\HardwareFulfilment\BoxFulfilmentCallbackGateway;
use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\OutboxEvent;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentCallbackRequest;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentCallbackProcessor
{
    public function __construct(
        private readonly BoxFulfilmentCallbackGateway $gateway,
        private readonly HardwareFulfilmentCallbackSigner $signer,
        private readonly HardwareFulfilmentWorkflowService $workflow,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $eventId = (string) ($payload['event_id'] ?? '');
        $sourceId = (string) ($payload['source_id'] ?? '');
        $fulfilmentId = (int) ($payload['hardware_fulfilment_id'] ?? 0);

        if ($eventId === '' || $fulfilmentId <= 0 || $sourceId === '') {
            throw new HardwareFulfilmentCallbackNonRetryableException('Callback outbox payload is missing event identity.');
        }

        if (HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)) {
            throw new HardwareFulfilmentCallbackNonRetryableException('Frozen pending hardware orders cannot send Box callbacks.');
        }

        if (! $this->deliveryEnabled()) {
            return;
        }

        $rawBody = HardwareFulfilmentCallbackPayload::encode($payload);
        $signed = $this->signer->sign($rawBody);
        $url = trim((string) config('hardware_fulfilment.callback.url', ''));

        $request = new HardwareFulfilmentCallbackRequest(
            eventId: $eventId,
            url: $url,
            rawBody: $rawBody,
            payload: $payload,
            headers: $signed['headers'],
        );

        $result = $this->gateway->send($request);

        if ($result->status === 'disabled') {
            return;
        }

        if ($result->accepted) {
            $this->acknowledgeShipped($payload);

            return;
        }

        $detail = $result->error ?? ('HTTP '.($result->httpStatus ?? 'unknown'));
        if ($result->retryable) {
            throw new HardwareFulfilmentCallbackRetryableException('Box fulfilment callback failed and may be retried: '.$detail);
        }

        throw new HardwareFulfilmentCallbackNonRetryableException('Box fulfilment callback was rejected: '.$detail);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function acknowledgeShipped(array $payload): void
    {
        if (($payload['state'] ?? null) !== HardwareFulfilmentState::Shipped->value) {
            return;
        }

        $fulfilment = HardwareFulfilment::query()->find((int) $payload['hardware_fulfilment_id']);
        if ($fulfilment === null || $fulfilment->state !== HardwareFulfilmentState::Shipped) {
            return;
        }

        try {
            $this->workflow->transition(
                $fulfilment,
                HardwareFulfilmentState::Synced,
                actorType: 'system',
                payload: [
                    'reason' => 'box_callback_ack',
                    'event_id' => $payload['event_id'] ?? null,
                ],
            );
        } catch (ValidationException) {
            // Stay SHIPPED. Callback already succeeded; SYNCED is display-ack only.
        }
    }

    private function deliveryEnabled(): bool
    {
        if (! (bool) config('hardware_fulfilment.callback.enabled', false)) {
            return false;
        }

        if ($this->gateway instanceof NullBoxFulfilmentCallbackGateway) {
            return false;
        }

        if (trim((string) config('hardware_fulfilment.callback.url', '')) === '') {
            return false;
        }

        return $this->signer->secret() !== null;
    }
}
