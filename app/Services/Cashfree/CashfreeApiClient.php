<?php

namespace App\Services\Cashfree;

use App\Services\Cashfree\Exceptions\CashfreeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CashfreeApiClient
{
    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->apiSecret() !== '';
    }

    public function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new CashfreeApiException(
                'Cashfree PG API credentials are not configured (CASHFREE_APP_ID / CASHFREE_API_SECRET).',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        return $this->getJson('/orders/'.$orderId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderPayments(string $orderId): array
    {
        $payload = $this->getJson('/orders/'.$orderId.'/payments');

        if ($payload === []) {
            return [];
        }

        // Cashfree may return a bare list or wrap under a key.
        if (array_is_list($payload)) {
            /** @var list<array<string, mixed>> $payload */
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach (['payments', 'data'] as $key) {
            $nested = $payload[$key] ?? null;

            if (is_array($nested) && array_is_list($nested)) {
                /** @var list<array<string, mixed>> $nested */
                return array_values(array_filter($nested, 'is_array'));
            }
        }

        throw new CashfreeApiException(
            'Cashfree payments response for '.$orderId.' was not a payment list.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $this->assertConfigured();

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl(), '/'))
                ->acceptJson()
                ->withHeaders([
                    'x-client-id' => $this->appId(),
                    'x-client-secret' => $this->apiSecret(),
                    'x-api-version' => $this->apiVersion(),
                ])
                ->connectTimeout((int) config('cashfree.api.connect_timeout_seconds', 5))
                ->timeout((int) config('cashfree.api.timeout_seconds', 15))
                ->get($path);
        } catch (ConnectionException $exception) {
            throw new CashfreeApiException(
                'Cashfree API connection failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new CashfreeApiException(sprintf(
                'Cashfree API GET %s failed with HTTP %d.',
                $path,
                $response->status(),
            ));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new CashfreeApiException(
                'Cashfree API GET '.$path.' returned a non-JSON object response.',
            );
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function appId(): string
    {
        return trim((string) config('cashfree.api.app_id'));
    }

    private function apiSecret(): string
    {
        return trim((string) config('cashfree.api.secret'));
    }

    private function baseUrl(): string
    {
        $base = trim((string) config('cashfree.api.base_url', 'https://api.cashfree.com/pg'));

        return $base !== '' ? $base : 'https://api.cashfree.com/pg';
    }

    private function apiVersion(): string
    {
        $version = trim((string) config('cashfree.api.version', '2026-01-01'));

        return $version !== '' ? $version : '2026-01-01';
    }
}
