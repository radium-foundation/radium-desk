<?php

namespace App\Services\HistoricalInvoice;

use App\Services\OrderLookup\OrderEnrichmentLookupService;
use App\Services\OrderLookup\SpokeOrderClientFactory;

class HistoricalInvoiceLookupService
{
    public const HISTORICAL_NUMBER = '/^IN[VS]\d{1,20}$/';

    public function __construct(
        private readonly SpokeOrderClientFactory $spokes,
        private readonly OrderEnrichmentLookupService $orders,
    ) {}

    public static function normalizeLookupQuery(string $identifier): string
    {
        return strtoupper(trim($identifier));
    }

    public static function looksLikeHistoricalInvoiceNumber(string $identifier): bool
    {
        return preg_match(self::HISTORICAL_NUMBER, self::normalizeLookupQuery($identifier)) === 1;
    }

    public static function looksLikeHistoricalOrderId(string $identifier): bool
    {
        return preg_match('/^(RDE|RIN|RA|RD)\d{3,20}$/', self::normalizeLookupQuery($identifier)) === 1;
    }

    public static function shouldOfferHistoricalLookup(string $identifier): bool
    {
        return self::looksLikeHistoricalInvoiceNumber($identifier)
            || self::looksLikeHistoricalOrderId($identifier);
    }

    public function lookup(string $identifier): HistoricalInvoiceResult
    {
        $trimmed = self::normalizeLookupQuery($identifier);

        if ($trimmed === '' || strlen($trimmed) > 64) {
            return new HistoricalInvoiceResult(
                eligibility: 'invalid',
                message: 'Enter a historical invoice number (INV…) or an order ID.',
            );
        }

        if (preg_match('/^INV-\d/', $trimmed) === 1) {
            return new HistoricalInvoiceResult(
                eligibility: 'statutory_invoice',
                invoiceNumber: $trimmed,
                message: 'That number belongs to the new statutory series. Open Statutory invoices instead.',
            );
        }

        if (self::looksLikeHistoricalInvoiceNumber($trimmed)) {
            return $this->lookupByInvoiceNumber($trimmed);
        }

        return $this->lookupByOrderId($trimmed);
    }

    private function lookupByInvoiceNumber(string $invoiceNumber): HistoricalInvoiceResult
    {
        $box = $this->spokes->make('radiumbox_com');
        if ($box->isConfigured()) {
            $payload = $box->fetchHistoricalInvoice($invoiceNumber);
            if (is_array($payload)) {
                $status = (int) ($payload['_http_status'] ?? 0);
                $eligibility = is_string($payload['eligibility'] ?? null)
                    ? $payload['eligibility']
                    : ($status === 200 ? 'historical_invoice' : 'not_found');

                if ($status === 200 && $eligibility === 'historical_invoice') {
                    $reprint = is_array($payload['data']['invoice'] ?? null) ? $payload['data']['invoice'] : null;
                    $number = is_string($reprint['invoice_number'] ?? null) ? $reprint['invoice_number'] : $invoiceNumber;

                    return new HistoricalInvoiceResult(
                        eligibility: 'historical_invoice',
                        invoiceNumber: $number,
                        reprint: $reprint,
                        source: 'radiumbox_com',
                        ordersId: is_numeric($reprint['orders_id'] ?? null) ? (int) $reprint['orders_id'] : null,
                        orderId: is_string($reprint['ordercode'] ?? null) ? $reprint['ordercode'] : null,
                    );
                }

                if (in_array($eligibility, ['paid_without_invoice', 'cancelled_or_unpaid', 'statutory_invoice', 'source_unavailable'], true)) {
                    return new HistoricalInvoiceResult(
                        eligibility: $eligibility,
                        invoiceNumber: $eligibility === 'statutory_invoice' ? $invoiceNumber : null,
                        message: is_string($payload['message'] ?? null) ? $payload['message'] : null,
                        source: 'radiumbox_com',
                    );
                }
            }
        }

        return new HistoricalInvoiceResult(
            eligibility: 'not_found',
            message: $box->isConfigured()
                ? 'No historical invoice with that exact number was found.'
                : 'Historical invoice source is not configured (radiumbox.com lookup is off).',
        );
    }

    private function lookupByOrderId(string $orderId): HistoricalInvoiceResult
    {
        $fetch = $this->orders->fetchFromSpokes($orderId);
        if ($fetch === null || ! $fetch->succeeded() || $fetch->enrichment === null) {
            if ($fetch !== null && $fetch->retriable) {
                return new HistoricalInvoiceResult(
                    eligibility: 'source_unavailable',
                    message: $fetch->errorMessage ?? 'Order source is temporarily unavailable.',
                    orderId: $orderId,
                );
            }

            return new HistoricalInvoiceResult(
                eligibility: $fetch === null ? 'unsupported_source' : 'not_found',
                message: $fetch === null
                    ? 'No authoritative order source is configured for this identifier.'
                    : 'Order was not found on the replacement APIs.',
                orderId: $orderId,
            );
        }

        $enrichment = $fetch->enrichment;
        $invoiceNumber = $enrichment->invoiceNumber;
        if (is_string($invoiceNumber) && preg_match(self::HISTORICAL_NUMBER, $invoiceNumber) === 1) {
            $byNumber = $this->lookupByInvoiceNumber($invoiceNumber);
            if ($byNumber->canReprint()) {
                return $byNumber;
            }

            return new HistoricalInvoiceResult(
                eligibility: 'historical_invoice',
                invoiceNumber: $invoiceNumber,
                reprint: $this->liteReprint($orderId, $enrichment),
                source: 'order_api',
                orderId: $orderId,
            );
        }

        $status = strtolower((string) ($enrichment->legacyOrderStatus ?? ''));
        $cancelled = in_array($status, ['reject', 'rejected', 'cancelled', 'canceled'], true);

        return new HistoricalInvoiceResult(
            eligibility: $cancelled ? 'cancelled_or_unpaid' : 'paid_without_invoice',
            message: $cancelled
                ? 'This order is cancelled or rejected and has no historical invoice.'
                : 'This order has no historical invoice number. Do not remint from this screen.',
            orderId: $orderId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function liteReprint(string $orderId, mixed $enrichment): array
    {
        return [
            'invoice_number' => $enrichment->invoiceNumber,
            'invoice_date' => null,
            'ordercode' => $orderId,
            'rdorderid' => $orderId,
            'seller' => [
                'legal_name' => 'Phil Technologies (P) Limited',
                'email' => 'mail@radiumbox.com',
                'phone' => '+91-84343 84343',
                'address' => null,
                'gstin' => null,
            ],
            'buyer' => [
                'name' => $enrichment->customerName,
                'email' => $enrichment->customerEmail,
                'phone' => $enrichment->customerPhone,
                'gst_no' => $enrichment->gstNumber,
                'address' => null,
            ],
            'lines' => [],
            'totals' => [
                'total' => null,
            ],
            'payment_status' => $enrichment->legacyOrderStatus,
            'read_only' => true,
            'lite' => true,
        ];
    }
}
