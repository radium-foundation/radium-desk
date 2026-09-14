<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\RadiumBoxPaymentConfirmationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RadiumBoxPaymentConfirmationClient
{
    /**
     * Ask Box to server-side verify Cashfree and mark the order Paid + enqueue handoff.
     */
    public function confirmPayment(
        string $gatewayOrderId,
        ?string $paymentId = null,
        bool $dryRun = false,
    ): RadiumBoxPaymentConfirmationResult {
        $baseUrl = $this->baseUrl();
        $token = $this->token();

        if ($baseUrl === null || $token === '') {
            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'not_configured',
                gatewayOrderId: $gatewayOrderId,
                errorMessage: 'RadiumBox payment confirmation is not configured.',
            );
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($token)
                ->connectTimeout((int) config('radiumbox.payment_confirm.connect_timeout_seconds', 3))
                ->timeout((int) config('radiumbox.payment_confirm.timeout_seconds', 15));

            $host = $this->requestHostHeader();
            if ($host !== null) {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $payload = array_filter([
                'gateway_order_id' => $gatewayOrderId,
                'payment_id' => $paymentId,
                'dry_run' => $dryRun ? true : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $response = $request->post('/api/integrations/v1/cashfree/confirm-payment', $payload);
            $body = $response->json();
            $body = is_array($body) ? $body : [];

            $status = (string) ($body['status'] ?? 'unknown');
            $okStatuses = ['paid', 'already_paid', 'dry_run'];
            $retriable = in_array($response->status(), [0, 408, 429, 500, 502, 503, 504], true)
                || $status === 'not_paid';

            return new RadiumBoxPaymentConfirmationResult(
                ok: in_array($status, $okStatuses, true),
                status: $status,
                gatewayOrderId: (string) ($body['gateway_order_id'] ?? $gatewayOrderId),
                businessOrderId: is_string($body['business_order_id'] ?? null) ? $body['business_order_id'] : null,
                paymentStatus: is_string($body['payment_status'] ?? null) ? $body['payment_status'] : null,
                firstPaid: (bool) ($body['first_paid'] ?? false),
                httpStatus: $response->status(),
                errorMessage: is_string($body['message'] ?? null) ? $body['message'] : null,
                handoff: is_array($body['handoff'] ?? null) ? $body['handoff'] : null,
                retriable: $retriable && ! in_array($status, $okStatuses, true),
            );
        } catch (ConnectionException $exception) {
            Log::warning('[RadiumBox payment confirm] Connection failed.', [
                'gateway_order_id' => $gatewayOrderId,
                'message' => $exception->getMessage(),
            ]);

            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'connection_error',
                gatewayOrderId: $gatewayOrderId,
                errorMessage: $exception->getMessage(),
                retriable: true,
            );
        } catch (RequestException $exception) {
            Log::warning('[RadiumBox payment confirm] Request failed.', [
                'gateway_order_id' => $gatewayOrderId,
                'http_status' => $exception->response?->status(),
                'message' => $exception->getMessage(),
            ]);

            return new RadiumBoxPaymentConfirmationResult(
                ok: false,
                status: 'request_error',
                gatewayOrderId: $gatewayOrderId,
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

    private function requestHostHeader(): ?string
    {
        $host = config('order_lookup.spokes.radiumbox_com.host');

        return is_string($host) && trim($host) !== '' ? trim($host) : null;
    }
}
