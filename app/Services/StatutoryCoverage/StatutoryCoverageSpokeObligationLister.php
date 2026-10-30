<?php

namespace App\Services\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageSourcePopulation;
use App\Data\StatutoryCoverage\StatutoryCoverageSpokeObligationReference;
use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\StatutoryInvoiceChannel;
use App\Services\OrderLookup\SpokeOrderClientFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class StatutoryCoverageSpokeObligationLister
{
    public function __construct(
        private readonly SpokeOrderClientFactory $spokeClients,
    ) {}

    /**
     * @return array{
     *     population: StatutoryCoverageSourcePopulation,
     *     source_ids: list<string>,
     *     references: array<string, StatutoryCoverageSpokeObligationReference>
     * }
     */
    public function collectSourcePopulation(
        StatutoryInvoiceChannel $channel,
        StatutoryCoverageWindow $window,
        int $perPage,
    ): array {
        $config = config('statutory_coverage.channels.'.$channel->value, []);
        $path = is_array($config) ? ($config['obligation_list_path'] ?? null) : null;
        $spokeKey = is_array($config) ? ($config['spoke'] ?? null) : null;

        if (! is_string($path) || $path === '' || ! is_string($spokeKey) || $spokeKey === '') {
            return $this->emptyResult(
                supported: false,
                reason: 'Spoke obligation list API is not registered for this channel.',
            );
        }

        $client = $this->spokeClients->make($spokeKey);
        if (! $client->isConfigured()) {
            return $this->emptyResult(
                supported: true,
                reason: 'Spoke lookup client is not configured (missing base URL or token).',
            );
        }

        $lookupConfig = config('order_lookup.spokes.'.$spokeKey, []);
        $baseUrl = is_array($lookupConfig) ? rtrim((string) ($lookupConfig['base_url'] ?? ''), '/') : '';
        $token = is_array($lookupConfig) ? trim((string) ($lookupConfig['token'] ?? '')) : '';

        $maxPages = max(1, (int) config('statutory_coverage.obligations.max_pages_per_fetch', 50));
        $maxRecords = max(1, (int) config('statutory_coverage.obligations.max_records_per_fetch', 50000));
        $perPage = max(1, min($perPage, (int) config('statutory_coverage.pagination.max_per_page', 2000)));

        $expectedSourceType = (string) config('statutory_coverage.default_source_type', 'commerce_order');

        $cursor = null;
        $seenCursors = [];
        $references = [];
        $sourceIds = [];
        $pagesFetched = 0;
        $spokeTotalCount = null;
        $snapshotConsistency = null;
        $fetchComplete = true;
        $failureReason = null;
        $lastNextCursor = null;

        try {
            while (true) {
                if ($pagesFetched >= $maxPages) {
                    $fetchComplete = false;
                    $failureReason = 'Spoke obligation list exceeded max page limit ('.$maxPages.').';
                    break;
                }

                if ($cursor !== null) {
                    if (isset($seenCursors[$cursor])) {
                        $fetchComplete = false;
                        $failureReason = 'Spoke obligation list returned a repeated cursor.';
                        break;
                    }
                    $seenCursors[$cursor] = true;
                }

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
                    return $this->emptyResult(supported: true, reason: 'Spoke obligation list authentication failed.');
                }

                if (! $response->successful()) {
                    return $this->emptyResult(
                        supported: true,
                        reason: 'Spoke obligation list HTTP '.$response->status().'.',
                    );
                }

                $body = $response->json();
                if (! is_array($body)) {
                    return $this->emptyResult(supported: true, reason: 'Spoke obligation list returned non-JSON.');
                }

                $pagesFetched++;

                if ($pagesFetched === 1) {
                    $populationMeta = is_array($body['population'] ?? null) ? $body['population'] : [];
                    if (isset($populationMeta['total_count']) && is_numeric($populationMeta['total_count'])) {
                        $spokeTotalCount = (int) $populationMeta['total_count'];
                    }
                    $snapshotConsistency = is_string($populationMeta['snapshot_consistency'] ?? null)
                        ? $populationMeta['snapshot_consistency']
                        : (is_string($body['snapshot_consistency'] ?? null) ? $body['snapshot_consistency'] : null);
                }

                foreach ($body['data'] ?? [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $parsed = $this->parseRow($row, $channel, $expectedSourceType);
                    if ($parsed === null) {
                        continue;
                    }

                    $key = $parsed->identityKey();
                    if (isset($references[$key])) {
                        $fetchComplete = false;
                        $failureReason = 'Spoke obligation list returned duplicate source identity '.$key.'.';
                        break 2;
                    }

                    $references[$key] = $parsed;
                    $sourceIds[] = $parsed->sourceId;
                }

                if (count($sourceIds) > $maxRecords) {
                    $fetchComplete = false;
                    $failureReason = 'Spoke obligation list exceeded max record limit ('.$maxRecords.').';
                    break;
                }

                $pageComplete = ($body['complete'] ?? false) === true;
                $lastNextCursor = isset($body['next_cursor']) && is_string($body['next_cursor']) && $body['next_cursor'] !== ''
                    ? $body['next_cursor']
                    : null;

                if ($pageComplete && $lastNextCursor === null) {
                    break;
                }

                if ($lastNextCursor === null) {
                    $fetchComplete = false;
                    $failureReason = 'Spoke obligation list pagination incomplete (missing next_cursor).';
                    break;
                }

                $cursor = $lastNextCursor;
            }
        } catch (ConnectionException) {
            return $this->emptyResult(supported: true, reason: 'Spoke obligation list request timed out.');
        } catch (\Throwable) {
            return $this->emptyResult(supported: true, reason: 'Spoke obligation list request failed.');
        }

        $authoritativeComplete = $fetchComplete
            && $failureReason === null
            && $snapshotConsistency !== 'best_effort';

        $population = new StatutoryCoverageSourcePopulation(
            complete: $fetchComplete,
            supported: true,
            count: count($sourceIds),
            failureReason: $failureReason,
            nextCursor: $lastNextCursor,
            snapshotConsistency: $snapshotConsistency,
            spokeTotalCount: $spokeTotalCount,
            pagesFetched: $pagesFetched,
            authoritativeComplete: $authoritativeComplete,
        );

        return [
            'population' => $population,
            'source_ids' => array_values(array_unique($sourceIds)),
            'references' => $references,
        ];
    }

    /**
     * @deprecated Use collectSourcePopulation for cursor-aware spokes.
     *
     * @return array{population: StatutoryCoverageSourcePopulation, source_ids: list<string>}
     */
    public function fetchPage(
        StatutoryInvoiceChannel $channel,
        StatutoryCoverageWindow $window,
        ?string $cursor,
        int $perPage,
    ): array {
        if ($cursor !== null && $cursor !== '') {
            $result = $this->collectSourcePopulation($channel, $window, $perPage);

            return [
                'population' => $result['population'],
                'source_ids' => $result['source_ids'],
            ];
        }

        $result = $this->collectSourcePopulation($channel, $window, $perPage);

        return [
            'population' => $result['population'],
            'source_ids' => $result['source_ids'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseRow(
        array $row,
        StatutoryInvoiceChannel $expectedChannel,
        string $expectedSourceType,
    ): ?StatutoryCoverageSpokeObligationReference {
        $sourceId = $row['source_id'] ?? null;
        if (! is_string($sourceId) || trim($sourceId) === '') {
            return null;
        }

        $channel = $row['channel'] ?? $expectedChannel->value;
        if (! is_string($channel) || $channel !== $expectedChannel->value) {
            return null;
        }

        $sourceType = $row['source_type'] ?? $expectedSourceType;
        if (! is_string($sourceType) || $sourceType !== $expectedSourceType) {
            return null;
        }

        $obligationClass = $row['obligation_class'] ?? null;

        return new StatutoryCoverageSpokeObligationReference(
            channel: $channel,
            sourceType: $sourceType,
            sourceId: strtoupper(trim($sourceId)),
            obligationClass: is_string($obligationClass) ? $obligationClass : null,
        );
    }

    /**
     * @return array{
     *     population: StatutoryCoverageSourcePopulation,
     *     source_ids: list<string>,
     *     references: array<string, StatutoryCoverageSpokeObligationReference>
     * }
     */
    private function emptyResult(bool $supported, string $reason): array
    {
        return [
            'population' => new StatutoryCoverageSourcePopulation(
                complete: false,
                supported: $supported,
                count: 0,
                failureReason: $reason,
                nextCursor: null,
                snapshotConsistency: null,
                spokeTotalCount: null,
                pagesFetched: 0,
                authoritativeComplete: false,
            ),
            'source_ids' => [],
            'references' => [],
        ];
    }
}
