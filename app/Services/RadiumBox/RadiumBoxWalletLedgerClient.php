<?php

namespace App\Services\RadiumBox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RadiumBoxWalletLedgerClient
{
    public function isConfigured(): bool
    {
        $config = config('order_lookup.spokes.radiumbox_com', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function fetchLedger(string $customerEmail, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('RadiumBox wallet ledger integration is not configured.');
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw new RuntimeException('RadiumBox storefront base URL is not configured for wallet ledger.');
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) config('order_lookup.spokes.radiumbox_com.connect_timeout_seconds', 3))
                ->timeout((int) config('order_lookup.spokes.radiumbox_com.timeout_seconds', 8));

            $host = trim((string) config('order_lookup.spokes.radiumbox_com.host', ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $request->get('/api/integrations/v1/wallet-ledger', array_filter([
                'customer_email' => strtolower(trim($customerEmail)),
                'limit' => $query['limit'] ?? null,
                'before_id' => $query['before_id'] ?? null,
                'type' => $query['type'] ?? null,
                'status' => $query['status'] ?? null,
                'order_code' => $query['order_code'] ?? null,
                'desk_refund_reference' => $query['desk_refund_reference'] ?? null,
                'reference' => $query['reference'] ?? null,
                'date_from' => $query['date_from'] ?? null,
                'date_to' => $query['date_to'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('RadiumBox wallet ledger API returned an invalid response.');
            }

            if ($response->failed()) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RadiumBox wallet ledger API request failed.';

                throw new RuntimeException($message, $response->status());
            }

            return $payload;
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException('RadiumBox wallet ledger API is temporarily unavailable.', 0, $exception);
        }
    }

    private function token(): string
    {
        return trim((string) config('order_lookup.spokes.radiumbox_com.token', ''));
    }

    private function baseUrl(): ?string
    {
        $baseUrl = rtrim((string) config('order_lookup.spokes.radiumbox_com.base_url', ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $parsed = parse_url($baseUrl);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        if ($scheme === 'https' || ($scheme === 'http' && $isLoopback)) {
            return $baseUrl;
        }

        return null;
    }
}
