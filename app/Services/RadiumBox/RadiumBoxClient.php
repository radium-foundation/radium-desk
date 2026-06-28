<?php

namespace App\Services\RadiumBox;

use App\Services\RadiumBox\Exceptions\RadiumBoxException;
use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RadiumBoxClient
{
    public function __construct(
        private readonly RadiumBoxOrderSearchResponseMapper $responseMapper,
        private readonly RadiumBoxRequestCache $requestCache,
    ) {}

    public function fetchOrderEnrichment(string $orderId): ?RadiumBoxOrderEnrichment
    {
        if (! config('radiumbox.enabled')) {
            return null;
        }

        return $this->requestCache->rememberOrderEnrichment($orderId, function () use ($orderId): ?RadiumBoxOrderEnrichment {
            try {
                $response = Http::baseUrl(config('radiumbox.base_url'))
                    ->acceptJson()
                    ->connectTimeout(config('radiumbox.connect_timeout_seconds'))
                    ->timeout(config('radiumbox.timeout_seconds'))
                    ->get('/api/search/order', [
                        'orderid' => $orderId,
                    ]);

                if ($response->failed() && ! $response->json()) {
                    throw new RadiumBoxInvalidResponseException(
                        'RadiumBox API request failed with HTTP '.$response->status().'.',
                    );
                }

                $payload = $response->json();

                if (! is_array($payload)) {
                    throw new RadiumBoxInvalidResponseException('RadiumBox API returned a non-JSON response.');
                }

                return $this->responseMapper->map($payload);
            } catch (RadiumBoxException $exception) {
                $this->logFailure($orderId, $exception);

                return null;
            } catch (ConnectionException $exception) {
                $this->logFailure($orderId, $exception);

                return null;
            } catch (RequestException $exception) {
                $this->logFailure($orderId, $exception);

                return null;
            }
        });
    }

    private function logFailure(string $orderId, \Throwable $exception): void
    {
        Log::warning('RadiumBox order lookup failed.', [
            'order_id' => $orderId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
