<?php

namespace App\Services\RadiumBox;

use App\Services\RadiumBox\Exceptions\RadiumBoxException;
use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
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
            $result = $this->performOrderLookup($orderId);

            if (! $result->succeeded()) {
                if ($result->errorMessage !== null) {
                    $this->logFailure($orderId, $result->errorType ?? 'lookup_failed', $result->errorMessage);
                }

                return null;
            }

            return $result->enrichment;
        });
    }

    public function fetchOrderEnrichmentForBackgroundSync(string $orderId): RadiumBoxOrderEnrichmentFetchResult
    {
        if (! config('radiumbox.enabled')) {
            return new RadiumBoxOrderEnrichmentFetchResult(
                retriable: false,
                errorMessage: 'RadiumBox integration is disabled.',
                errorType: 'disabled',
            );
        }

        return $this->performOrderLookup($orderId);
    }

    private function performOrderLookup(string $orderId): RadiumBoxOrderEnrichmentFetchResult
    {
        try {
            $response = Http::baseUrl(config('radiumbox.base_url'))
                ->acceptJson()
                ->connectTimeout(config('radiumbox.connect_timeout_seconds'))
                ->timeout(config('radiumbox.timeout_seconds'))
                ->get('/api/search/order', [
                    'orderid' => $orderId,
                ]);

            if ($response->failed() && ! $response->json()) {
                return new RadiumBoxOrderEnrichmentFetchResult(
                    retriable: true,
                    errorMessage: 'RadiumBox API request failed with HTTP '.$response->status().'.',
                    errorType: 'http_error',
                );
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return new RadiumBoxOrderEnrichmentFetchResult(
                    retriable: true,
                    errorMessage: 'RadiumBox API returned a non-JSON response.',
                    errorType: 'invalid_response',
                );
            }

            $enrichment = $this->responseMapper->map($payload);

            return new RadiumBoxOrderEnrichmentFetchResult(
                retriable: false,
                enrichment: $enrichment,
            );
        } catch (RadiumBoxOrderNotFoundException $exception) {
            return new RadiumBoxOrderEnrichmentFetchResult(
                retriable: false,
                errorMessage: $exception->getMessage(),
                errorType: 'order_not_found',
            );
        } catch (RadiumBoxInvalidResponseException $exception) {
            return new RadiumBoxOrderEnrichmentFetchResult(
                retriable: true,
                errorMessage: $exception->getMessage(),
                errorType: 'invalid_response',
            );
        } catch (RadiumBoxException $exception) {
            return new RadiumBoxOrderEnrichmentFetchResult(
                retriable: true,
                errorMessage: $exception->getMessage(),
                errorType: $exception::class,
            );
        } catch (ConnectionException $exception) {
            return new RadiumBoxOrderEnrichmentFetchResult(
                retriable: true,
                errorMessage: $exception->getMessage(),
                errorType: 'connection_error',
            );
        } catch (RequestException $exception) {
            return new RadiumBoxOrderEnrichmentFetchResult(
                retriable: true,
                errorMessage: $exception->getMessage(),
                errorType: 'request_error',
            );
        }
    }

    private function logFailure(string $orderId, string $errorType, string $message): void
    {
        Log::warning('RadiumBox order lookup failed.', [
            'order_id' => $orderId,
            'error_type' => $errorType,
            'message' => $message,
        ]);
    }
}
