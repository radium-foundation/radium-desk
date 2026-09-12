<?php

namespace App\Services\HistoricalSearch;

use App\Contracts\HistoricalSearchRepository;
use App\Data\HistoricalSearchHit;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class CanonicalHistoricalSearchRepository implements HistoricalSearchRepository
{
    public function __construct(
        private readonly HistoricalSearchQueryNormalizer $normalizer,
    ) {}

    /**
     * @return list<HistoricalSearchHit>
     */
    public function search(string $query, int $limit): array
    {
        $parsed = $this->normalizer->normalize($query);
        $token = $parsed['token'];

        if ($token === '') {
            return [];
        }

        $hits = [];

        $hits = array_merge($hits, $this->searchSearchDocumentsByToken($token, $limit));

        if ($parsed['email'] !== null) {
            $hits = array_merge($hits, $this->searchSearchDocumentsByEmail($parsed['email'], $limit));
        }

        if ($parsed['phone'] !== null) {
            $hits = array_merge($hits, $this->searchSearchDocumentsByPhone($parsed['phone'], $limit));
        }

        if ($parsed['name_prefix'] !== null) {
            $hits = array_merge($hits, $this->searchSearchDocumentsByName($parsed['name_prefix'], $limit));
        }

        if ($parsed['looks_like_serial']) {
            $hits = array_merge($hits, $this->searchSerials($token, $limit));
        }

        $hits = array_merge($hits, $this->searchShipmentsByAwb($token, $limit));
        $hits = array_merge($hits, $this->searchOrderItemsByProduct($token, $limit));

        return $this->dedupe($hits, $limit);
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchSearchDocumentsByToken(string $token, int $limit): array
    {
        $rows = $this->connection()
            ->table('hist_search_document')
            ->select([
                'document_type',
                'entity_id',
                'title',
                'subtitle',
                'occurred_on',
                'source_lineage',
            ])
            ->where('token_exact', $token)
            ->limit($limit)
            ->get();

        return $this->mapSearchDocumentRows($rows);
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchSearchDocumentsByEmail(string $email, int $limit): array
    {
        $rows = $this->connection()
            ->table('hist_search_document')
            ->select([
                'document_type',
                'entity_id',
                'title',
                'subtitle',
                'occurred_on',
                'source_lineage',
            ])
            ->where('email_norm', $email)
            ->limit($limit)
            ->get();

        return $this->mapSearchDocumentRows($rows);
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchSearchDocumentsByPhone(string $phone, int $limit): array
    {
        $rows = $this->connection()
            ->table('hist_search_document')
            ->select([
                'document_type',
                'entity_id',
                'title',
                'subtitle',
                'occurred_on',
                'source_lineage',
            ])
            ->where('phone_norm', $phone)
            ->limit($limit)
            ->get();

        return $this->mapSearchDocumentRows($rows);
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchSearchDocumentsByName(string $namePrefix, int $limit): array
    {
        $rows = $this->connection()
            ->table('hist_search_document')
            ->select([
                'document_type',
                'entity_id',
                'title',
                'subtitle',
                'occurred_on',
                'source_lineage',
            ])
            ->where('name_search', 'like', $namePrefix.'%')
            ->limit($limit)
            ->get();

        return $this->mapSearchDocumentRows($rows);
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchSerials(string $token, int $limit): array
    {
        $rows = $this->connection()
            ->table('hist_serial as hs')
            ->leftJoin('hist_order as ho', 'ho.id', '=', 'hs.hist_order_id')
            ->select([
                'hs.id as entity_id',
                'hs.serial_number as title',
                'ho.public_code as subtitle',
                'ho.order_lineage as source_lineage',
                'hs.source_database',
                'hs.source_table',
                'hs.source_pk',
            ])
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(TRIM(hs.serial_number), CHAR(9), ''), CHAR(10), ''), CHAR(13), '') = ?",
                [trim($token)],
            )
            ->limit($limit)
            ->get();

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = new HistoricalSearchHit(
                documentType: 'serial',
                entityId: (int) $row->entity_id,
                title: (string) $row->title,
                subtitle: (string) ($row->subtitle ?? ''),
                occurredOn: null,
                sourceLineage: (string) ($row->source_lineage ?: 'serial'),
                sourceDatabase: $row->source_database !== null ? (string) $row->source_database : null,
                sourceTable: $row->source_table !== null ? (string) $row->source_table : null,
                sourcePk: $row->source_pk !== null ? (string) $row->source_pk : null,
            );
        }

        return $hits;
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchShipmentsByAwb(string $token, int $limit): array
    {
        $rows = $this->connection()
            ->table('hist_shipment as hs')
            ->join('hist_order as ho', 'ho.id', '=', 'hs.hist_order_id')
            ->select([
                'hs.id as entity_id',
                'hs.awb as title',
                'ho.public_code as subtitle',
                'ho.order_lineage as source_lineage',
                'ho.source_database',
                'ho.source_table',
                'ho.source_pk',
            ])
            ->where('hs.awb', $token)
            ->limit($limit)
            ->get();

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = new HistoricalSearchHit(
                documentType: 'shipment',
                entityId: (int) $row->entity_id,
                title: (string) $row->title,
                subtitle: (string) ($row->subtitle ?? ''),
                occurredOn: null,
                sourceLineage: (string) ($row->source_lineage ?: 'shipment'),
                sourceDatabase: $row->source_database !== null ? (string) $row->source_database : null,
                sourceTable: $row->source_table !== null ? (string) $row->source_table : null,
                sourcePk: $row->source_pk !== null ? (string) $row->source_pk : null,
            );
        }

        return $hits;
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchOrderItemsByProduct(string $token, int $limit): array
    {
        $rows = $this->connection()
            ->table('hist_order_item as hoi')
            ->join('hist_order as ho', 'ho.id', '=', 'hoi.hist_order_id')
            ->select([
                'hoi.id as entity_id',
                DB::raw('COALESCE(hoi.product_ref, hoi.product_name) as title'),
                'ho.public_code as subtitle',
                'ho.order_lineage as source_lineage',
                'hoi.source_database',
                'hoi.source_table',
                'hoi.source_pk',
            ])
            ->where(function ($builder) use ($token): void {
                $builder->where('hoi.product_ref', $token)
                    ->orWhere('hoi.product_name', $token);
            })
            ->limit($limit)
            ->get();

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = new HistoricalSearchHit(
                documentType: 'order_item',
                entityId: (int) $row->entity_id,
                title: (string) ($row->title ?? ''),
                subtitle: (string) ($row->subtitle ?? ''),
                occurredOn: null,
                sourceLineage: (string) ($row->source_lineage ?: 'order_item'),
                sourceDatabase: $row->source_database !== null ? (string) $row->source_database : null,
                sourceTable: $row->source_table !== null ? (string) $row->source_table : null,
                sourcePk: $row->source_pk !== null ? (string) $row->source_pk : null,
            );
        }

        return $hits;
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return list<HistoricalSearchHit>
     */
    private function mapSearchDocumentRows(iterable $rows): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $lineage = (string) $row->source_lineage;
            $hits[] = new HistoricalSearchHit(
                documentType: (string) $row->document_type,
                entityId: (int) $row->entity_id,
                title: (string) $row->title,
                subtitle: (string) ($row->subtitle ?? ''),
                occurredOn: $row->occurred_on !== null ? (string) $row->occurred_on : null,
                sourceLineage: $lineage,
                partialIngest: $lineage === 'rd_service',
            );
        }

        return $hits;
    }

    /**
     * @param  list<HistoricalSearchHit>  $hits
     * @return list<HistoricalSearchHit>
     */
    private function dedupe(array $hits, int $limit): array
    {
        $seen = [];
        $out = [];

        foreach ($hits as $hit) {
            $key = $hit->documentType.'#'.$hit->entityId;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $hit;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function connection(): Connection
    {
        $connection = DB::connection((string) config('historical_search.connection', 'radium_hist'));
        $timeoutMs = (int) config('historical_search.timeout_ms', 400);
        $seconds = max(0.05, $timeoutMs / 1000);

        try {
            $connection->statement('SET SESSION max_statement_time = ?', [$seconds]);
        } catch (\Throwable) {
            // Older engines may not support max_statement_time; rely on PHP timeout + circuit breaker.
        }

        return $connection;
    }
}
