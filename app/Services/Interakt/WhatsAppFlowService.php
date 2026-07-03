<?php

namespace App\Services\Interakt;

use App\Data\Interakt\WhatsAppFlowContext;
use App\Models\Incident;
use App\Services\Interakt\Exceptions\WhatsAppFlowTokenException;
use App\Services\SupportAppointmentUrlService;

class WhatsAppFlowService
{
    public function __construct(
        private readonly SupportAppointmentUrlService $bookingUrlService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generateFlowContextPayload(Incident $incident): array
    {
        return $this->buildContext($incident)->toArray();
    }

    public function buildContext(Incident $incident): WhatsAppFlowContext
    {
        $incident->loadMissing(['order.deviceModel']);
        $order = $incident->order;

        if ($order === null) {
            throw new WhatsAppFlowTokenException('Incident has no associated order.');
        }

        $ttlHours = (int) config('interakt.flow_token_ttl_hours', 24);

        return new WhatsAppFlowContext(
            incident_id: $incident->id,
            incident_reference: $incident->display_reference,
            order_id: (string) $order->order_id,
            customer_name: $order->customer_name,
            customer_phone: $order->customer_phone,
            brand: $order->deviceModel?->brand,
            model: $order->displayDeviceModelName(),
            serial_number: $order->serial_number,
            booking_url: $this->bookingUrlService->bookingUrl($incident),
            expires_at: now()->addHours($ttlHours),
        );
    }

    public function generateToken(Incident $incident): string
    {
        return $this->generateTokenFromContext($this->buildContext($incident));
    }

    public function generateTokenFromContext(WhatsAppFlowContext $context): string
    {
        $payload = $this->encodePayload($context->toArray());
        $signature = hash_hmac('sha256', $payload, $this->signingKey());

        return $payload.'.'.$signature;
    }

    public function validateToken(string $token): WhatsAppFlowContext
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            throw new WhatsAppFlowTokenException('Malformed flow token.');
        }

        [$payload, $signature] = $parts;

        if (! hash_equals(hash_hmac('sha256', $payload, $this->signingKey()), $signature)) {
            throw new WhatsAppFlowTokenException('Invalid flow token signature.');
        }

        $decoded = $this->decodePayload($payload);

        if ($decoded === false) {
            throw new WhatsAppFlowTokenException('Invalid flow token payload.');
        }

        $data = json_decode($decoded, true);

        if (! is_array($data)) {
            throw new WhatsAppFlowTokenException('Invalid flow token payload.');
        }

        $context = WhatsAppFlowContext::fromArray($data);

        if ($context->expires_at->isPast()) {
            throw new WhatsAppFlowTokenException('Flow token has expired.');
        }

        return $context;
    }

    public function resolveIncident(string $token): Incident
    {
        $context = $this->validateToken($token);

        $incident = Incident::query()->find($context->incident_id);

        if ($incident === null) {
            throw new WhatsAppFlowTokenException('Incident not found for flow token.');
        }

        return $incident;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encodePayload(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    }

    private function decodePayload(string $payload): string|false
    {
        $normalized = strtr($payload, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($normalized, true);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
