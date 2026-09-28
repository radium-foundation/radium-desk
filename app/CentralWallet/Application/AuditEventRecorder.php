<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAuditEvent;
use Illuminate\Support\Str;

final class AuditEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        ?string $centralWalletId,
        AuditActorType $actorType,
        ?string $actorId,
        ?string $correlationId,
        array $payload = [],
    ): CentralWalletAuditEvent {
        return CentralWalletAuditEvent::query()->create([
            'event_type' => $eventType,
            'central_wallet_id' => $centralWalletId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'payload' => $this->sanitizePayload($payload),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $redactedKeys = ['authorization', 'token', 'password', 'secret', 'otp'];

        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $redactedKeys, true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
