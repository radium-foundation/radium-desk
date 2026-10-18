<?php

namespace App\Services\RadiumBox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class RadiumBoxCatalogAvailabilityClient
{
    public function isConfigured(): bool
    {
        if (! config('radiumbox.availability_sync.enabled')) {
            return false;
        }

        $config = config('order_lookup.spokes.radiumbox_com', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    /**
     * @param  array{
     *     channel: string,
     *     model_id: int,
     *     desk_product_id: int,
     *     available_qty: int,
     *     inventory_tracking: string,
     *     source_as_of: string,
     *     idempotency_key: string,
     *     payload_version: int,
     * }  $payload
     */
    public function syncAvailability(array $payload): void
    {
        if (! $this->isConfigured()) {
            throw new RadiumBoxCatalogAvailabilitySyncException('Storefront availability sync is not configured.');
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw new RadiumBoxCatalogAvailabilitySyncException('RadiumBox storefront base URL is not configured.');
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->withHeaders(['Idempotency-Key' => $payload['idempotency_key']])
                ->connectTimeout((int) config('order_lookup.spokes.radiumbox_com.connect_timeout_seconds', 3))
                ->timeout((int) config('order_lookup.spokes.radiumbox_com.timeout_seconds', 8));

            $host = trim((string) config('order_lookup.spokes.radiumbox_com.host', ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $request->post('/api/integrations/v1/catalog-availability', $payload);

            if ($response->failed()) {
                $body = $response->json();
                $message = is_array($body) && is_string($body['message'] ?? null)
                    ? $body['message']
                    : 'RadiumBox availability API request failed.';

                throw new RadiumBoxCatalogAvailabilitySyncException(
                    $message,
                    retriable: $response->serverError() || $response->status() === 429,
                );
            }
        } catch (ConnectionException|RequestException $exception) {
            throw new RadiumBoxCatalogAvailabilitySyncException($exception->getMessage(), retriable: true, previous: $exception);
        }
    }

    private function token(): string
    {
        return trim((string) config('order_lookup.spokes.radiumbox_com.token', ''));
    }

    private function baseUrl(): ?string
    {
        $base = trim((string) config('order_lookup.spokes.radiumbox_com.base_url', ''));
        if ($base === '') {
            return null;
        }

        return rtrim($base, '/');
    }
}
