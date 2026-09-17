<?php

namespace App\Services\HistoricalSearch;

use App\Data\HistoricalSearch\HistoricalOrderSummary;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

class HistoricalOrderSummaryService
{
    public function __construct(
        private readonly HistoricalPaymentPresenter $paymentPresenter,
        private readonly HistoricalTenurePresenter $tenurePresenter,
        private readonly HistoricalSearchProvenanceLabel $provenanceLabel,
    ) {}

    public function forOrderId(int $histOrderId): ?HistoricalOrderSummary
    {
        if ($histOrderId <= 0) {
            return null;
        }

        try {
            $order = $this->connection()
                ->table('hist_order')
                ->select([
                    'id',
                    'public_code',
                    'order_date',
                    'payment_status',
                    'total_amount',
                    'currency',
                    'order_lineage',
                    'status',
                ])
                ->where('id', $histOrderId)
                ->first();

            if ($order === null) {
                return null;
            }

            return $this->buildSummary($order);
        } catch (Throwable) {
            return null;
        }
    }

    public function forPublicCode(string $publicCode): ?HistoricalOrderSummary
    {
        $token = strtoupper(trim($publicCode));

        if ($token === '') {
            return null;
        }

        try {
            $order = $this->connection()
                ->table('hist_order')
                ->select([
                    'id',
                    'public_code',
                    'order_date',
                    'payment_status',
                    'total_amount',
                    'currency',
                    'order_lineage',
                    'status',
                ])
                ->where('public_code', $token)
                ->orderByDesc('id')
                ->first();

            if ($order === null) {
                return null;
            }

            return $this->buildSummary($order);
        } catch (Throwable) {
            return null;
        }
    }

    public function forDocument(string $documentType, int $entityId): ?HistoricalOrderSummary
    {
        if ($documentType === 'order') {
            return $this->forOrderId($entityId);
        }

        $histOrderId = $this->resolveHistOrderId($documentType, $entityId);

        if ($histOrderId === null) {
            return null;
        }

        return $this->forOrderId($histOrderId);
    }

    private function resolveHistOrderId(string $documentType, int $entityId): ?int
    {
        try {
            return match ($documentType) {
                'serial' => $this->scalarInt(
                    $this->connection()
                        ->table('hist_serial')
                        ->where('id', $entityId)
                        ->value('hist_order_id'),
                ),
                'invoice' => $this->scalarInt(
                    $this->connection()
                        ->table('hist_invoice')
                        ->where('id', $entityId)
                        ->value('hist_order_id'),
                ),
                'shipment' => $this->scalarInt(
                    $this->connection()
                        ->table('hist_shipment')
                        ->where('id', $entityId)
                        ->value('hist_order_id'),
                ),
                'customer' => null,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function buildSummary(stdClass $order): HistoricalOrderSummary
    {
        $histOrderId = (int) $order->id;
        $orderDate = $this->stringOrNull($order->order_date);
        $payment = $this->paymentPresenter->present($this->stringOrNull($order->payment_status));
        $tenure = $this->tenurePresenter->present(
            $this->stringOrNull($order->order_lineage),
            $this->stringOrNull($order->status),
            $orderDate,
        );

        return new HistoricalOrderSummary(
            histOrderId: $histOrderId,
            orderId: $this->stringOrNull($order->public_code),
            orderDate: $orderDate,
            orderYear: $this->yearFromDate($orderDate),
            paymentDisplay: $payment['display'],
            paymentDate: $payment['date'],
            orderAmount: $this->formatAmount($order->total_amount, $this->stringOrNull($order->currency)),
            invoiceNumber: $this->primaryInvoiceNumber($histOrderId),
            awb: $this->primaryAwb($histOrderId),
            productModel: $this->primaryProductModel($histOrderId),
            serialNumber: $this->primarySerialNumber($histOrderId),
            tenureDisplay: $tenure['display'],
            tenureEndDate: $tenure['end_date'],
            provenance: $this->provenanceRows($histOrderId),
        );
    }

    private function primaryInvoiceNumber(int $histOrderId): ?string
    {
        try {
            $value = $this->connection()
                ->table('hist_invoice')
                ->where('hist_order_id', $histOrderId)
                ->orderByDesc('issued_date')
                ->orderByDesc('id')
                ->value('invoice_number');

            return $this->stringOrNull($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function primaryAwb(int $histOrderId): ?string
    {
        try {
            $value = $this->connection()
                ->table('hist_shipment')
                ->where('hist_order_id', $histOrderId)
                ->whereNotNull('awb')
                ->orderByDesc('id')
                ->value('awb');

            return $this->stringOrNull($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function primaryProductModel(int $histOrderId): ?string
    {
        try {
            $row = $this->connection()
                ->table('hist_order_item')
                ->select(['product_name', 'product_ref'])
                ->where('hist_order_id', $histOrderId)
                ->orderBy('line_no')
                ->orderBy('id')
                ->first();

            if ($row === null) {
                return null;
            }

            $name = $this->stringOrNull($row->product_name);
            $ref = $this->stringOrNull($row->product_ref);

            if ($name !== null && $ref !== null && $name !== $ref) {
                return "{$name} ({$ref})";
            }

            return $name ?? $ref;
        } catch (Throwable) {
            return null;
        }
    }

    private function primarySerialNumber(int $histOrderId): ?string
    {
        try {
            $value = $this->connection()
                ->table('hist_serial')
                ->where('hist_order_id', $histOrderId)
                ->orderByDesc('id')
                ->value('serial_number');

            return $this->stringOrNull($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function provenanceRows(int $histOrderId): array
    {
        try {
            $rows = $this->connection()
                ->table('hist_provenance')
                ->select([
                    'source_lineage',
                    'source_database',
                    'source_table',
                    'source_pk',
                ])
                ->where('entity_type', 'order')
                ->where('entity_id', $histOrderId)
                ->orderBy('id')
                ->limit(5)
                ->get();

            return $rows->map(function (stdClass $row): array {
                $label = $this->provenanceLabel->forLineage(
                    (string) $row->source_lineage,
                    $this->stringOrNull($row->source_database),
                );

                $value = implode(' · ', array_filter([
                    $this->stringOrNull($row->source_table),
                    $this->stringOrNull($row->source_pk),
                ], fn (?string $part): bool => $part !== null));

                return [
                    'label' => $label,
                    'value' => $value !== '' ? $value : '—',
                ];
            })->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function connection(): Connection
    {
        $connection = DB::connection((string) config('historical_search.connection', 'radium_hist'));
        $timeoutMs = (int) config('historical_search.timeout_ms', 400);
        $seconds = max(0.05, $timeoutMs / 1000);

        try {
            $connection->statement('SET SESSION max_statement_time = ?', [$seconds]);
        } catch (Throwable) {
            // Older engines may not support max_statement_time; rely on PHP timeout + circuit breaker.
        }

        return $connection;
    }

    private function formatAmount(mixed $amount, ?string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $formatted = number_format((float) $amount, 2, '.', '');
        $currency = $currency ?? 'INR';

        return "{$currency} {$formatted}";
    }

    private function yearFromDate(?string $orderDate): ?int
    {
        if ($orderDate === null || strlen($orderDate) < 4) {
            return null;
        }

        $year = (int) substr($orderDate, 0, 4);

        return $year > 1900 ? $year : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function scalarInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
