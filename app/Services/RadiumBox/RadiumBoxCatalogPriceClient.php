<?php

namespace App\Services\RadiumBox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class RadiumBoxCatalogPriceClient
{
    public function isConfigured(): bool
    {
        if (! config('radiumbox.catalog_price_sync.enabled')) {
            return false;
        }

        $config = config('order_lookup.spokes.radiumbox_com', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    /**
     * @return array{
     *     publish_price: float,
     *     selling_price: float,
     *     liveprice: int,
     *     gst_percentage: float,
     *     effective_at: string,
     * }
     */
    public function syncCatalogPrice(
        int $deskProductId,
        int $modelId,
        float $publishPrice,
        float $gstPercentage,
        string $idempotencyKey,
    ): array {
        if (! $this->isConfigured()) {
            throw new RadiumBoxCatalogPriceSyncException('Storefront catalog price sync is not configured.');
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw new RadiumBoxCatalogPriceSyncException('RadiumBox storefront base URL is not configured.');
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->connectTimeout((int) config('order_lookup.spokes.radiumbox_com.connect_timeout_seconds', 3))
                ->timeout((int) config('order_lookup.spokes.radiumbox_com.timeout_seconds', 8));

            $host = trim((string) config('order_lookup.spokes.radiumbox_com.host', ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $request->post('/api/integrations/v1/catalog-prices', [
                'desk_product_id' => $deskProductId,
                'model_id' => $modelId,
                'publish_price' => round($publishPrice, 2),
                'gst_percentage' => round($gstPercentage, 2),
                'idempotency_key' => $idempotencyKey,
            ]);

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RadiumBoxCatalogPriceSyncException('RadiumBox catalog price API returned an invalid response.');
            }

            if ($response->failed()) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RadiumBox catalog price API request failed.';

                throw new RadiumBoxCatalogPriceSyncException($message, retriable: $response->serverError() || $response->status() === 429);
            }

            $publish = data_get($payload, 'data.publish_price');
            $selling = data_get($payload, 'data.selling_price');
            $liveprice = data_get($payload, 'data.liveprice');
            $gst = data_get($payload, 'data.gst_percentage');
            $effectiveAt = data_get($payload, 'data.effective_at');

            if (! is_numeric($publish) || ! is_numeric($selling) || ! is_numeric($liveprice) || ! is_numeric($gst) || ! is_string($effectiveAt)) {
                throw new RadiumBoxCatalogPriceSyncException('RadiumBox catalog price API did not return applied price details.');
            }

            return [
                'publish_price' => round((float) $publish, 2),
                'selling_price' => round((float) $selling, 2),
                'liveprice' => (int) $liveprice,
                'gst_percentage' => round((float) $gst, 2),
                'effective_at' => $effectiveAt,
            ];
        } catch (RadiumBoxCatalogPriceSyncException $exception) {
            throw $exception;
        } catch (ConnectionException|RequestException $exception) {
            throw new RadiumBoxCatalogPriceSyncException(
                'RadiumBox catalog price API is temporarily unavailable.',
                retriable: true,
                previous: $exception,
            );
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
