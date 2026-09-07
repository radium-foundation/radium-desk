<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentInboundCallbackService
{
    public function __construct(
        private readonly HardwareFulfilmentCallbackSigner $signer,
        private readonly HardwareFulfilmentWorkflowService $workflow,
    ) {}

    /**
     * @return array{ok: bool, http: int, body: array<string, mixed>}
     */
    public function handle(Request $request): array
    {
        if (! (bool) config('hardware_fulfilment.callback.inbound_enabled', false)) {
            return [
                'ok' => false,
                'http' => 404,
                'body' => [
                    'accepted' => false,
                    'error' => 'Inbound fulfilment-status is disabled.',
                ],
            ];
        }

        $auth = $this->signer->verify($request);
        if (($auth['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'http' => ($auth['replay'] ?? false) ? 409 : 401,
                'body' => [
                    'accepted' => false,
                    'error' => $auth['error'] ?? 'Callback authentication failed.',
                    'replay' => (bool) ($auth['replay'] ?? false),
                ],
            ];
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'ok' => false,
                'http' => 400,
                'body' => [
                    'accepted' => false,
                    'error' => 'Request body must be JSON.',
                ],
            ];
        }

        if (! is_array($payload)) {
            return [
                'ok' => false,
                'http' => 400,
                'body' => [
                    'accepted' => false,
                    'error' => 'Request body must be a JSON object.',
                ],
            ];
        }

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $sourceId = trim((string) ($payload['source_id'] ?? ''));
        $fulfilmentId = (int) ($payload['hardware_fulfilment_id'] ?? 0);

        if ($eventId === '' || $sourceId === '' || $fulfilmentId <= 0) {
            return [
                'ok' => false,
                'http' => 422,
                'body' => [
                    'accepted' => false,
                    'error' => 'Callback payload is missing event or order identity.',
                ],
            ];
        }

        if (HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)
            || HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId)) {
            return [
                'ok' => false,
                'http' => 422,
                'body' => [
                    'accepted' => false,
                    'error' => 'This hardware order cannot accept fulfilment-status callbacks.',
                ],
            ];
        }

        $fulfilment = HardwareFulfilment::query()->find($fulfilmentId);
        if ($fulfilment === null || strcasecmp((string) $fulfilment->source_id, $sourceId) !== 0) {
            return [
                'ok' => false,
                'http' => 422,
                'body' => [
                    'accepted' => false,
                    'error' => 'Callback fulfilment identity does not match a Desk hardware fulfilment.',
                ],
            ];
        }

        $existing = HardwareFulfilmentEvent::query()
            ->where('hardware_fulfilment_id', $fulfilment->id)
            ->where('actor_type', 'box_inbound')
            ->get()
            ->first(fn (HardwareFulfilmentEvent $event): bool => ($event->payload['event_id'] ?? null) === $eventId);

        if ($existing !== null) {
            return [
                'ok' => true,
                'http' => 200,
                'body' => [
                    'accepted' => true,
                    'duplicate' => true,
                    'state' => $fulfilment->state->value,
                ],
            ];
        }

        HardwareFulfilmentEvent::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'from_state' => $fulfilment->state,
            'to_state' => $fulfilment->state,
            'actor_type' => 'box_inbound',
            'actor_id' => null,
            'payload' => [
                'reason' => 'inbound_fulfilment_status',
                'event_id' => $eventId,
                'event_type' => $payload['event_type'] ?? null,
                'state' => $payload['state'] ?? null,
            ],
            'created_at' => now(),
        ]);

        if (($payload['state'] ?? null) === HardwareFulfilmentState::Shipped->value
            && $fulfilment->state === HardwareFulfilmentState::Shipped) {
            try {
                $this->workflow->markSynced(
                    $fulfilment,
                    actorType: 'system',
                    payload: [
                        'reason' => 'inbound_fulfilment_status_ack',
                        'event_id' => $eventId,
                    ],
                );
            } catch (ValidationException) {
                // Stay SHIPPED. Inbound receipt is already recorded.
            }
        }

        $fresh = $fulfilment->fresh() ?? $fulfilment;

        return [
            'ok' => true,
            'http' => 200,
            'body' => [
                'accepted' => true,
                'duplicate' => false,
                'state' => $fresh->state->value,
            ],
        ];
    }
}
