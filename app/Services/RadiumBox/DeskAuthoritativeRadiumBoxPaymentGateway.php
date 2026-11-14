<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\RadiumBoxPaymentConfirmationResult;
use App\Models\Order;
use App\Services\RadiumBox\Contracts\RadiumBoxPaymentDownstreamGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Desk-authoritative payment event delivery (Box must not call Cashfree).
 */
final class DeskAuthoritativeRadiumBoxPaymentGateway implements RadiumBoxPaymentDownstreamGateway
{
    public const CONTRACT = 'desk_authoritative_v1';

    public function __construct(
        private readonly DeskAuthoritativePaymentEventFactory $eventFactory,
        private readonly DeskAuthoritativePaymentEventSigner $signer,
    ) {}

    public function contractName(): string
    {
        return self::CONTRACT;
    }

    public function notifyPaid(Order $order, bool $dryRun = false): RadiumBoxPaymentConfirmationResult
    {
        $baseUrl = $this->baseUrl();
        $token = $this->token();

        if ($baseUrl === null || $token === '') {
            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'not_configured',
                gatewayOrderId: $order->order_id,
                errorMessage: 'RadiumBox authoritative payment delivery is not configured.',
            );
        }

        try {
            $event = $this->eventFactory->fromDeskOrder($order);
        } catch (Throwable $exception) {
            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'rejected',
                gatewayOrderId: $order->order_id,
                errorMessage: $exception->getMessage(),
                retriable: false,
            );
        }

        $path = (string) config('radiumbox.payment_confirm.authoritative.path', '/api/integrations/v1/desk/payment-events');
        $secret = $this->signingSecret($token);
        $signature = $this->signer->sign($event, $secret);
        $payload = $event->payload();
        if ($dryRun) {
            $payload['dry_run'] = true;
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($token)
                ->withHeaders([
                    'X-Desk-Event-Id' => $event->eventId,
                    'X-Desk-Idempotency-Key' => $event->idempotencyKey,
                    'X-Desk-Signature' => $signature,
                ])
                ->connectTimeout((int) config('radiumbox.payment_confirm.connect_timeout_seconds', 3))
                ->timeout((int) config('radiumbox.payment_confirm.timeout_seconds', 15));

            $host = $this->requestHostHeader();
            if ($host !== null) {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $request->post($path, $payload);
            $body = $response->json();
            $body = is_array($body) ? $body : [];

            $status = (string) ($body['status'] ?? 'unknown');
            $okStatuses = ['accepted', 'duplicate_ack', 'paid', 'already_paid', 'dry_run'];
            $retriable = in_array($response->status(), [408, 429, 500, 502, 503, 504], true);

            return new RadiumBoxPaymentConfirmationResult(
                ok: in_array($status, $okStatuses, true),
                status: $status,
                gatewayOrderId: (string) ($body['gateway_order_id'] ?? $event->businessOrderId),
                businessOrderId: is_string($body['business_order_id'] ?? null) ? $body['business_order_id'] : $event->businessOrderId,
                paymentStatus: is_string($body['payment_status'] ?? null) ? $body['payment_status'] : 'Paid',
                firstPaid: (bool) ($body['first_paid'] ?? false),
                httpStatus: $response->status(),
                errorMessage: is_string($body['message'] ?? null) ? $body['message'] : null,
                handoff: is_array($body['handoff'] ?? null) ? $body['handoff'] : null,
                retriable: $retriable && ! in_array($status, $okStatuses, true),
            );
        } catch (ConnectionException $exception) {
            Log::warning('[RadiumBox authoritative payment] Connection failed.', [
                'business_order_id' => $event->businessOrderId,
                'event_id' => $event->eventId,
                'message' => $exception->getMessage(),
            ]);

            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'connection_error',
                gatewayOrderId: $event->businessOrderId,
                errorMessage: $exception->getMessage(),
                retriable: true,
            );
        } catch (RequestException $exception) {
            Log::warning('[RadiumBox authoritative payment] Request failed.', [
                'business_order_id' => $event->businessOrderId,
                'event_id' => $event->eventId,
                'http_status' => $exception->response?->status(),
                'message' => $exception->getMessage(),
            ]);

            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'request_error',
                gatewayOrderId: $event->businessOrderId,
                httpStatus: $exception->response?->status(),
                errorMessage: $exception->getMessage(),
                retriable: in_array($exception->response?->status(), [408, 429, 500, 502, 503, 504], true),
            );
        }
    }

    private function baseUrl(): ?string
    {
        $configured = config('order_lookup.spokes.radiumbox_com.base_url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $legacy = config('radiumbox.base_url');
        if (is_string($legacy) && $legacy !== '') {
            return rtrim($legacy, '/');
        }

        return null;
    }

    private function token(): string
    {
        $token = config('order_lookup.spokes.radiumbox_com.token');

        return is_string($token) ? trim($token) : '';
    }

    private function signingSecret(string $token): string
    {
        $configured = config('radiumbox.payment_confirm.authoritative.signing_secret');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return $token;
    }

    private function requestHostHeader(): ?string
    {
        $host = config('order_lookup.spokes.radiumbox_com.host');

        return is_string($host) && trim($host) !== '' ? trim($host) : null;
    }
}