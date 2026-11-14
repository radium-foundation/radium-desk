<?php

namespace App\Data\RadiumBox;

use Illuminate\Support\Carbon;

/**
 * Canonical Desk → RadiumBox payment notification (Desk is payment authority).
 */
final class DeskAuthoritativePaymentEvent
{
    public const CONTRACT_VERSION = 'desk_authoritative_payment_v1';

    public function __construct(
        public readonly string $eventId,
        public readonly string $idempotencyKey,
        public readonly string $businessOrderId,
        public readonly int $deskOrderId,
        public readonly string $provider,
        public readonly string $paymentStatus,
        public readonly string $currency,
        public readonly string $amount,
        public readonly string $cashfreePaymentId,
        public readonly ?string $cashfreeOrderId,
        public readonly ?string $bankReference,
        public readonly Carbon $verifiedAt,
        public readonly string $verificationSource,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'event_id' => $this->eventId,
            'idempotency_key' => $this->idempotencyKey,
            'business_order_id' => $this->businessOrderId,
            'desk_order_id' => $this->deskOrderId,
            'payment' => array_filter([
                'provider' => $this->provider,
                'status' => $this->paymentStatus,
                'currency' => $this->currency,
                'amount' => $this->amount,
                'cashfree_payment_id' => $this->cashfreePaymentId,
                'cashfree_order_id' => $this->cashfreeOrderId,
                'bank_reference' => $this->bankReference,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'verification' => [
                'verified_by' => 'radium_desk',
                'source' => $this->verificationSource,
                'verified_at' => $this->verifiedAt->toIso8601String(),
            ],
        ];
    }

    public function canonicalJson(): string
    {
        return $this->transmitJson(false);
    }

    /**
     * Bytes sent on the wire must be signed and posted identically (JSON_UNESCAPED_SLASHES).
     */
    public function transmitJson(bool $dryRun = false): string
    {
        $payload = $this->payload();
        if ($dryRun) {
            $payload['dry_run'] = true;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}