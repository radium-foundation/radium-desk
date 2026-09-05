<?php

namespace App\Services\OrderLookup;

use App\Models\Order;
use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use App\Services\RdService\RdServiceFetchResult;
use App\Services\RdService\RdServiceOrderId;
use App\Services\RdService\RdServiceOrderMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpokeOrderClient
{
    public function __construct(
        private readonly RdServiceOrderMapper $mapper,
        private readonly string $source,
    ) {}

    public function source(): string
    {
        return $this->source;
    }

    public function isConfigured(): bool
    {
        $config = $this->config();
        if (! (bool) ($config['enabled'] ?? false)) {
            return false;
        }

        if ($this->token() === '') {
            return false;
        }

        return $this->originBaseUrl() !== null;
    }

    public function isEligible(?string $orderId): bool
    {
        if (! $this->isConfigured() || ! is_string($orderId) || $orderId === '') {
            return false;
        }

        if (Order::isInquiryOrderId($orderId)) {
            return false;
        }

        $accepts = $this->config()['accepts'] ?? [];
        if (Order::isHardwareOrderId($orderId)) {
            $prefix = strtoupper(substr(trim($orderId), 0, 3));

            return ($prefix === 'RDE' && in_array('rde', $accepts, true))
                || ($prefix === 'RIN' && in_array('rin', $accepts, true));
        }

        if (preg_match('/^RIN[0-9A-Za-z]{1,61}$/', trim($orderId)) === 1) {
            return in_array('rin', $accepts, true);
        }

        return in_array('rd', $accepts, true) && RdServiceOrderId::isValid($orderId);
    }

    public function fetch(string $orderId): RdServiceFetchResult
    {
        if (! $this->isConfigured()) {
            return $this->skip('not_configured', 'Spoke '.$this->source.' is not configured.');
        }

        if (! $this->isEligible($orderId)) {
            return $this->skip('invalid_order_id', 'Order ID is not valid for '.$this->source.'.');
        }

        $baseUrl = $this->originBaseUrl();
        if ($baseUrl === null) {
            return $this->skip('insecure_base_url', 'Spoke base URL must be HTTPS or loopback HTTP with a Host.');
        }

        $normalized = trim($orderId);

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) ($this->config()['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($this->config()['timeout_seconds'] ?? 8));

            $host = $this->requestHostHeader();
            if ($host !== null) {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $request->get('/api/integrations/v1/rd-orders/'.rawurlencode($normalized));

            return $this->interpretResponse($normalized, $response->status(), $response->json(), $response->header('Retry-After'));
        } catch (ConnectionException $exception) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: $this->redact($exception->getMessage()),
                errorType: 'connection_error',
            );
        } catch (RequestException $exception) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: $this->redact($exception->getMessage()),
                errorType: 'request_error',
            );
        } catch (RadiumBoxOrderNotFoundException $exception) {
            return $this->notFound($this->redact($exception->getMessage()));
        } catch (RadiumBoxInvalidResponseException $exception) {
            return $this->malformed($this->redact($exception->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchHistoricalInvoice(string $invoiceNumber): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $baseUrl = $this->originBaseUrl();
        $path = $this->config()['historical_invoice_path'] ?? null;
        if ($baseUrl === null || ! is_string($path) || $path === '') {
            return null;
        }

        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withToken($this->token())
            ->connectTimeout((int) ($this->config()['connect_timeout_seconds'] ?? 3))
            ->timeout((int) ($this->config()['timeout_seconds'] ?? 8));

        $host = $this->requestHostHeader();
        if ($host !== null) {
            $request = $request->withHeaders(['Host' => $host]);
        }

        $response = $request->get(rtrim($path, '/').'/'.rawurlencode($invoiceNumber));
        $payload = $response->json();

        return is_array($payload) ? $payload + ['_http_status' => $response->status()] : [
            '_http_status' => $response->status(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = config('order_lookup.spokes.'.$this->source, []);

        return is_array($config) ? $config : [];
    }

    private function interpretResponse(string $orderId, int $status, mixed $payload, ?string $retryAfter): RdServiceFetchResult
    {
        if ($status === 401) {
            Log::error('Spoke order lookup authentication failed.', [
                'source' => $this->source,
                'order_id' => $orderId,
                'http_status' => 401,
            ]);

            return new RdServiceFetchResult(
                retriable: false,
                fallbackToAdmin: false,
                errorMessage: 'Spoke authentication failed.',
                errorType: 'unauthorized',
                httpStatus: 401,
            );
        }

        if ($status === 404) {
            return $this->notFound('Spoke order not found.', 404);
        }

        if ($status === 429) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: 'Spoke API rate limit exceeded (HTTP 429).',
                errorType: 'rate_limited',
                httpStatus: 429,
                retryAfterSeconds: ctype_digit((string) $retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($status >= 500 || $status === 408) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: 'Spoke API request failed with HTTP '.$status.'.',
                errorType: 'http_error',
                httpStatus: $status,
            );
        }

        if ($status !== 200 || ! is_array($payload)) {
            return $this->malformed('Spoke API returned HTTP '.$status.'.', $status);
        }

        try {
            $enrichment = $this->mapper->map($payload, $orderId);
        } catch (RadiumBoxOrderNotFoundException $exception) {
            return $this->notFound($this->redact($exception->getMessage()), $status);
        } catch (RadiumBoxInvalidResponseException $exception) {
            return $this->malformed($this->redact($exception->getMessage()), $status);
        }

        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            enrichment: $enrichment,
            httpStatus: $status,
        );
    }

    private function skip(string $errorType, string $message): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            errorMessage: $message,
            errorType: $errorType,
        );
    }

    private function notFound(string $message, ?int $httpStatus = null): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            errorMessage: $message,
            errorType: 'order_not_found',
            httpStatus: $httpStatus,
        );
    }

    private function malformed(string $message, ?int $httpStatus = null): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            errorMessage: $message,
            errorType: 'invalid_response',
            httpStatus: $httpStatus,
        );
    }

    private function originBaseUrl(): ?string
    {
        $baseUrl = rtrim((string) ($this->config()['base_url'] ?? ''), '/');
        $lower = strtolower($baseUrl);

        if ($baseUrl === '') {
            return null;
        }

        if (str_starts_with($lower, 'https://')) {
            return $baseUrl;
        }

        if (preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $lower) !== 1) {
            return null;
        }

        return $this->requestHostHeader() === null ? null : $baseUrl;
    }

    private function requestHostHeader(): ?string
    {
        $host = trim((string) ($this->config()['host'] ?? ''));

        if ($host === '' || preg_match('/^[A-Za-z0-9.-]+\z/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    private function token(): string
    {
        return trim((string) ($this->config()['token'] ?? ''));
    }

    private function redact(string $message): string
    {
        $token = $this->token();

        if ($token !== '' && str_contains($message, $token)) {
            return str_replace($token, '[redacted]', $message);
        }

        return $message;
    }
}
