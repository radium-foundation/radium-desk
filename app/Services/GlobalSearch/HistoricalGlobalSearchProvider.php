<?php

namespace App\Services\GlobalSearch;

use App\Contracts\GlobalSearchProvider;
use App\Contracts\HistoricalSearchRepository;
use App\Data\GlobalSearchResult;
use App\Data\HistoricalSearchHit;
use App\Models\User;
use App\Services\HistoricalSearch\HistoricalSearchCircuitBreaker;
use App\Services\HistoricalSearch\HistoricalSearchProvenanceLabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HistoricalGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(
        private readonly HistoricalSearchRepository $repository,
        private readonly HistoricalSearchCircuitBreaker $circuitBreaker,
        private readonly HistoricalSearchProvenanceLabel $provenanceLabel,
    ) {}

    public function type(): string
    {
        return 'historical';
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    public function search(User $user, string $query): Collection
    {
        if (! config('historical_search.enabled') || $this->circuitBreaker->isOpen()) {
            return collect();
        }

        $limit = (int) config('historical_search.max_results', 10);

        try {
            $hits = $this->repository->search($query, $limit);
            $this->circuitBreaker->recordSuccess();

            return collect($hits)
                ->map(fn (HistoricalSearchHit $hit): GlobalSearchResult => $this->toResult($hit));
        } catch (\Throwable $exception) {
            $this->circuitBreaker->recordFailure();
            Log::warning('historical_search.provider_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return collect();
        }
    }

    private function toResult(HistoricalSearchHit $hit): GlobalSearchResult
    {
        $provenanceLabel = $this->provenanceLabel->forLineage(
            $hit->sourceLineage,
            $hit->sourceDatabase,
        );

        $payload = [
            'document_type' => $hit->documentType,
            'title' => $hit->title,
            'subtitle' => $hit->subtitle,
            'occurred_on' => $hit->occurredOn,
            'source_lineage' => $hit->sourceLineage,
            'source_database' => $hit->sourceDatabase,
            'source_table' => $hit->sourceTable,
            'source_pk' => $hit->sourcePk,
            'source_lineage_label' => $provenanceLabel,
            'is_authoritative' => false,
            'partial_ingest' => $hit->partialIngest,
            'historical_only' => true,
        ];

        if ($hit->documentType === 'order') {
            $payload['hist_order_id'] = $hit->entityId;
            $payload['summary_url'] = route('historical-orders.show', ['histOrder' => $hit->entityId]);
        } else {
            $payload['summary_url'] = route('historical-orders.document', [
                'document_type' => $hit->documentType,
                'entity_id' => $hit->entityId,
            ]);
        }

        return new GlobalSearchResult(
            type: $this->type(),
            entityId: $hit->entityId,
            url: '#historical-search',
            payload: $payload,
        );
    }
}
