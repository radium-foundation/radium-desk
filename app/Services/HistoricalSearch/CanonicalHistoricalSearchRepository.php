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
        private readonly HistoricalSearchQueryClassifier $classifier,
        private readonly HistoricalCanonicalEmailNormalizer $emailNormalizer,
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

        $strategies = $this->classifier->strategies($token);
        $hits = [];

        foreach ($strategies as $strategy) {
            $hits = array_merge($hits, match ($strategy) {
                'token' => $this->searchSearchDocumentsByToken($token, $limit),
                'email' => $parsed['email'] !== null
                    ? $this->searchSearchDocumentsByEmail($parsed['email'], $limit)
                    : [],
                'phone' => $parsed['phone'] !== null
                    ? $this->searchSearchDocumentsByPhone($parsed['phone'], $limit)
                    : [],
                'name' => $parsed['name_prefix'] !== null
                    ? $this->searchSearchDocumentsByName($parsed['name_prefix'], $limit)
                    : [],
                'serial' => $this->searchSerials($token, $limit),
                'awb' => $this->searchShipmentsByAwb($token, $limit),
                'product' => $this->searchOrderItemsByProduct($token, $limit),
                default => [],
            });

            if (count($hits) >= $limit && in_array($strategy, ['token', 'email', 'phone'], true)) {
                break;
            }
        }

        $deduped = $this->dedupe($hits, $limit);

        return $this->hydrateProvenance($deduped);
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

        return $this->mapSearchDocumentRows($rows, false);
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchSearchDocumentsByEmail(string $email, int $limit): array
    {
        $variants = $this->emailNormalizer->searchVariants($email);

        if ($variants === []) {
            return [];
        }

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
            ->whereIn('email_norm', $variants)
            ->limit($limit)
            ->get();

        return $this->mapSearchDocumentRows($rows, false);
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

        return $this->mapSearchDocumentRows($rows, false);
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

        return $this->mapSearchDocumentRows($rows, false);
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
            );
        }

        return $hits;
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    private function searchOrderItemsByProduct(string $token, int $limit): array
    {
        $builder = $this->connection()
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
            ->limit($limit);

        if (preg_match('/^\d+$/', $token) === 1) {
            $builder->where('hoi.product_ref', $token);
        } else {
            $builder->where('hoi.product_ref', $token);
        }

        $rows = $builder->get();

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
    private function mapSearchDocumentRows(iterable $rows, bool $withProvenance): array
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

        return $withProvenance ? $this->hydrateProvenance($hits) : $hits;
    }

    /**
     * @param  list<HistoricalSearchHit>  $hits
     * @return list<HistoricalSearchHit>
     */
    private function hydrateProvenance(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $byType = [];
        foreach ($hits as $index => $hit) {
            if ($hit->sourceDatabase !== null) {
                continue;
            }

            $entityType = match ($hit->documentType) {
                'order_item' => 'order_item',
                'serial' => 'serial',
                'shipment' => 'shipment',
                'invoice' => 'invoice',
                'customer' => 'customer',
                default => $hit->documentType,
            };

            $byType[$entityType][] = $index;
        }

        if ($byType === []) {
            return $hits;
        }

        $lookup = [];
        foreach ($byType as $entityType => $indexes) {
            $ids = array_map(fn (int $i): int => $hits[$i]->entityId, $indexes);
            $rows = $this->connection()
                ->table('hist_provenance')
                ->select(['entity_type', 'entity_id', 'source_database', 'source_table', 'source_pk'])
                ->where('entity_type', $entityType)
                ->whereIn('entity_id', $ids)
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $key = $entityType.'#'.$row->entity_id;
                if (! isset($lookup[$key])) {
                    $lookup[$key] = [
                        'source_database' => $row->source_database !== null ? (string) $row->source_database : null,
                        'source_table' => $row->source_table !== null ? (string) $row->source_table : null,
                        'source_pk' => $row->source_pk !== null ? (string) $row->source_pk : null,
                    ];
                }
            }
        }

        $out = [];
        foreach ($hits as $hit) {
            if ($hit->sourceDatabase !== null) {
                $out[] = $hit;

                continue;
            }

            $entityType = match ($hit->documentType) {
                'order_item' => 'order_item',
                'serial' => 'serial',
                'shipment' => 'shipment',
                'invoice' => 'invoice',
                'customer' => 'customer',
                default => $hit->documentType,
            };

            $prov = $lookup[$entityType.'#'.$hit->entityId] ?? [];

            $out[] = new HistoricalSearchHit(
                documentType: $hit->documentType,
                entityId: $hit->entityId,
                title: $hit->title,
                subtitle: $hit->subtitle,
                occurredOn: $hit->occurredOn,
                sourceLineage: $hit->sourceLineage,
                sourceDatabase: $prov['source_database'] ?? null,
                sourceTable: $prov['source_table'] ?? null,
                sourcePk: $prov['source_pk'] ?? null,
                partialIngest: $hit->partialIngest,
            );
        }

        return $out;
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
