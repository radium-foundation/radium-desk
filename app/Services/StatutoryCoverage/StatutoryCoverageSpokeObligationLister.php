<?php

namespace App\Services\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageSourcePopulation;
use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\StatutoryInvoiceChannel;
use App\Services\OrderLookup\SpokeOrderClientFactory;
use Illuminate\Support\Facades\Http;

final class StatutoryCoverageSpokeObligationLister
{
    public function __construct(
        private readonly SpokeOrderClientFactory $spokeClients,
    ) {}

    /**
     * @return array{population: StatutoryCoverageSourcePopulation, source_ids: list<string>}
     */
    public function fetchPage(
        StatutoryInvoiceChannel $channel,
        StatutoryCoverageWindow $window,
        ?string $cursor,
        int $perPage,
    ): array {
        $config = config('statutory_coverage.channels.'.$channel->value, []);
        $path = is_array($config) ? ($config['obligation_list_path'] ?? null) : null;
        $spokeKey = is_array($config) ? ($config['spoke'] ?? null) : null;

        if (! is_string($path) || $path === '' || ! is_string($spokeKey) || $spokeKey === '') {
            return [
                'population' => new StatutoryCoverageSourcePopulation(
                    complete: false,
                    supported: false,
                    count: 0,
                    failureReason: 'Spoke obligation list API is not registered for this channel.',
                    nextCursor: null,
                ),
                'source_ids' => [],
            ];
        }

        $client = $this->spokeClients->make($spokeKey);
        if (! $client->isConfigured()) {
            return [
                'population' => new StatutoryCoverageSourcePopulation(
                    complete: false,
                    supported: true,
                    count: 0,
                    failureReason: 'Spoke lookup client is not configured (missing base URL or token).',
                    nextCursor: null,
                ),
                'source_ids' => [],
            ];
        }

        $lookupConfig = config('order_lookup.spokes.'.$spokeKey, []);
        $baseUrl = is_array($lookupConfig) ? rtrim((string) ($lookupConfig['base_url'] ?? ''), '/') : '';
        $token = is_array($lookupConfig) ? trim((string) ($lookupConfig['token'] ?? '')) : '';

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($token)
                ->connectTimeout((int) ($lookupConfig['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($lookupConfig['timeout_seconds'] ?? 8))
                ->get($path, array_filter([
                    'from' => $window->from->toIso8601String(),
                    'to' => $window->to->toIso8601String(),
                    'timezone' => $window->timezone,
                    'cursor' => $cursor,
                    'per_page' => $perPage,
                ]));

            if ($response->status() === 401) {
                return [
                    'population' => new StatutoryCoverageSourcePopulation(
                        complete: false,
                        supported: true,
                        count: 0,
                        failureReason: 'Spoke obligation list authentication failed.',
                        nextCursor: null,
                    ),
                    'source_ids' => [],
                ];
            }

            if (! $response->successful()) {
                return [
                    'population' => new StatutoryCoverageSourcePopulation(
                        complete: false,
                        supported: true,
                        count: 0,
                        failureReason: 'Spoke obligation list HTTP '.$response->status().'.',
                        nextCursor: null,
                    ),
                    'source_ids' => [],
                ];
            }

            $body = $response->json();
            if (! is_array($body)) {
                return [
                    'population' => new StatutoryCoverageSourcePopulation(
                        complete: false,
                        supported: true,
                        count: 0,
                        failureReason: 'Spoke obligation list returned non-JSON.',
                        nextCursor: null,
                    ),
                    'source_ids' => [],
                ];
            }

            $ids = [];
            foreach ($body['data'] ?? $body['orders'] ?? [] as $row) {
                if (is_array($row) && is_string($row['source_id'] ?? null) && $row['source_id'] !== '') {
                    $ids[] = strtoupper(trim($row['source_id']));
                } elseif (is_string($row) && $row !== '') {
                    $ids[] = strtoupper(trim($row));
                }
            }

            $next = isset($body['next_cursor']) && is_string($body['next_cursor']) ? $body['next_cursor'] : null;
            $complete = ($body['complete'] ?? ($next === null)) === true || $next === null;

            return [
                'population' => new StatutoryCoverageSourcePopulation(
                    complete: $complete,
                    supported: true,
                    count: count($ids),
                    failureReason: null,
                    nextCursor: $next,
                ),
                'source_ids' => array_values(array_unique($ids)),
            ];
        } catch (\Throwable) {
            return [
                'population' => new StatutoryCoverageSourcePopulation(
                    complete: false,
                    supported: true,
                    count: 0,
                    failureReason: 'Spoke obligation list request failed.',
                    nextCursor: null,
                ),
                'source_ids' => [],
            ];
        }
    }
}
