<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;

/**
 * Test/contract simulator for Box display apply.
 *
 * Box-side apply is UNKNOWN in this repository. Desk does not mutate fulfilment
 * state from inbound callbacks. This projection only shows how a receiver
 * should ignore older sequences so they cannot regress a newer state.
 */
final class HardwareFulfilmentCallbackProjection
{
    /**
     * @var array<string, array{sequence: int, state: string, event_id: string}>
     */
    private array $bySource = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $payload): bool
    {
        $sourceId = (string) ($payload['source_id'] ?? '');
        $sequence = (int) ($payload['sequence'] ?? 0);
        $state = (string) ($payload['state'] ?? '');
        $eventId = (string) ($payload['event_id'] ?? '');

        if ($sourceId === '' || $sequence <= 0 || $state === '' || $eventId === '') {
            return false;
        }

        $existing = $this->bySource[$sourceId] ?? null;
        if ($existing !== null) {
            if ($eventId === $existing['event_id']) {
                return true;
            }
            if ($sequence < $existing['sequence']) {
                return false;
            }
            if ($this->rank($state) < $this->rank($existing['state'])) {
                return false;
            }
        }

        $this->bySource[$sourceId] = [
            'sequence' => $sequence,
            'state' => $state,
            'event_id' => $eventId,
        ];

        return true;
    }

    public function stateFor(string $sourceId): ?string
    {
        return $this->bySource[$sourceId]['state'] ?? null;
    }

    public function sequenceFor(string $sourceId): ?int
    {
        return $this->bySource[$sourceId]['sequence'] ?? null;
    }

    private function rank(string $state): int
    {
        $enum = HardwareFulfilmentState::tryFrom($state);

        return $enum?->rank() ?? -1;
    }
}
