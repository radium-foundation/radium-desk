<?php

namespace App\Services\RdService;

use App\Models\Order;
use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RdServiceClient
{
    public function __construct(
        private readonly RdServiceOrderMapper $mapper,
    ) {}

    public function isConfigured(): bool
    {
        if (! (bool) config('rdservice.enabled', true)) {
            return false;
        }

        if ($this->token() === '') {
            return false;
        }

        return $this->httpsBaseUrl() !== null;
    }

    public function isEligible(?string $orderId): bool
    {
        if (! $this->isConfigured() || ! RdServiceOrderId::isValid($orderId)) {
            return false;
        }

        return ! Order::isHardwareOrderId($orderId) && ! Order::isInquiryOrderId($orderId);
    }

    public function fetch(string $orderId): RdServiceFetchResult
    {
        if (! (bool) config('rdservice.enabled', true)) {
            return $this->skip('disabled', 'RDService integration is disabled.');
        }

        if ($this->token() === '') {
            return $this->skip('not_configured', 'RDService API token is not configured.');
        }

        $baseUrl = $this->httpsBaseUrl();

        if ($baseUrl === null) {
            Log::error('RDService base URL is missing or not HTTPS; skipping lookup.', [
                'order_id' => $orderId,
            ]);

            return $this->skip('insecure_base_url', 'RDService base URL must be HTTPS.');
        }

        $normalized = RdServiceOrderId::normalize($orderId);

        if ($normalized === null || Order::isHardwareOrderId($normalized) || Order::isInquiryOrderId($normalized)) {
            return $this->skip('invalid_order_id', 'RD order ID is not valid for RDService lookup.');
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) config('rdservice.connect_timeout_seconds', 3))
                ->timeout((int) config('rdservice.timeout_seconds', 8))
                ->get('/api/integrations/v1/rd-orders/'.rawurlencode($normalized));

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

    private function interpretResponse(string $orderId, int $status, mixed $payload, ?string $retryAfter): RdServiceFetchResult
    {
        if ($status === 401) {
            Log::error('RDService authentication failed.', [
                'order_id' => $orderId,
                'http_status' => 401,
            ]);

            return new RdServiceFetchResult(
                retriable: false,
                fallbackToAdmin: true,
                errorMessage: 'RDService authentication failed.',
                errorType: 'unauthorized',
                httpStatus: 401,
            );
        }

        if ($status === 404) {
            return $this->notFound('RDService order not found.', 404);
        }

        if ($status === 429) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: 'RDService API rate limit exceeded (HTTP 429).',
                errorType: 'rate_limited',
                httpStatus: 429,
                retryAfterSeconds: $this->parseRetryAfter($retryAfter),
            );
        }

        if ($status >= 500 || $status === 408) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: 'RDService API request failed with HTTP '.$status.'.',
                errorType: 'http_error',
                httpStatus: $status,
            );
        }

        if ($status !== 200) {
            return $this->malformed('RDService API returned HTTP '.$status.'.', $status);
        }

        if (! is_array($payload)) {
            return $this->malformed('RDService API returned a non-JSON response.', $status);
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
            fallbackToAdmin: true,
            errorMessage: $message,
            errorType: $errorType,
        );
    }

    private function notFound(string $message, ?int $httpStatus = null): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: true,
            errorMessage: $message,
            errorType: 'order_not_found',
            httpStatus: $httpStatus,
        );
    }

    private function malformed(string $message, ?int $httpStatus = null): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: true,
            errorMessage: $message,
            errorType: 'invalid_response',
            httpStatus: $httpStatus,
        );
    }

    private function httpsBaseUrl(): ?string
    {
        $baseUrl = rtrim((string) config('rdservice.base_url'), '/');

        if ($baseUrl === '' || ! str_starts_with(strtolower($baseUrl), 'https://')) {
            return null;
        }

        return $baseUrl;
    }

    private function token(): string
    {
        return trim((string) config('rdservice.token'));
    }

    private function redact(string $message): string
    {
        $token = $this->token();

        if ($token !== '' && str_contains($message, $token)) {
            return str_replace($token, '[redacted]', $message);
        }

        return $message;
    }

    private function parseRetryAfter(?string $retryAfter): ?int
    {
        if ($retryAfter === null || trim($retryAfter) === '') {
            return null;
        }

        $retryAfter = trim($retryAfter);

        if (ctype_digit($retryAfter)) {
            return max(0, (int) $retryAfter);
        }

        $retryAt = strtotime($retryAfter);

        if ($retryAt === false) {
            return null;
        }

        return max(0, $retryAt - time());
    }
}
