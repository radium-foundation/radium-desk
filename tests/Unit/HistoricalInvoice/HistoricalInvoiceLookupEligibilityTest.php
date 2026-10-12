<?php

namespace Tests\Unit\HistoricalInvoice;

use App\Services\HistoricalInvoice\HistoricalInvoiceLookupService;
use App\Services\OrderLookup\OrderEnrichmentLookupService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Services\RdService\RdServiceFetchResult;
use Tests\TestCase;

class HistoricalInvoiceLookupEligibilityTest extends TestCase
{
    public function test_should_offer_historical_lookup_only_for_invoice_number_patterns(): void
    {
        $this->assertTrue(HistoricalInvoiceLookupService::shouldOfferHistoricalLookup('INV6745886'));
        $this->assertFalse(HistoricalInvoiceLookupService::shouldOfferHistoricalLookup('RD2579'));
        $this->assertFalse(HistoricalInvoiceLookupService::shouldOfferHistoricalLookup('RD268507'));
    }

    public function test_pending_current_order_does_not_expose_historical_finance_fallback(): void
    {
        $enrichment = new RadiumBoxOrderEnrichment(
            legacyOrderStatus: 'Pending',
            invoiceNumber: null,
            customerName: 'Test Customer',
            customerPhone: '9999999999',
            customerEmail: 'test@example.com',
        );

        $fetch = new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            enrichment: $enrichment,
            httpStatus: 200,
        );

        $orders = $this->createMock(OrderEnrichmentLookupService::class);
        $orders->method('fetchFromSpokes')->with('RD2579')->willReturn($fetch);

        $this->app->instance(OrderEnrichmentLookupService::class, $orders);

        $service = app(HistoricalInvoiceLookupService::class);

        $this->assertFalse($service->shouldExposeHistoricalFinanceFallback('RD2579'));
        $this->assertFalse($service->lookupForCustomer360('RD2579')->canReprint());
    }

    public function test_spoke_not_found_does_not_expose_historical_finance_fallback(): void
    {
        $fetch = new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            enrichment: null,
            httpStatus: 404,
        );

        $orders = $this->createMock(OrderEnrichmentLookupService::class);
        $orders->method('fetchFromSpokes')->with('RD3999999')->willReturn($fetch);

        $this->app->instance(OrderEnrichmentLookupService::class, $orders);

        $service = app(HistoricalInvoiceLookupService::class);

        $this->assertFalse($service->shouldExposeHistoricalFinanceFallback('RD3999999'));
        $this->assertFalse($service->lookupForCustomer360('RD3999999')->canReprint());
    }

    public function test_spoke_source_unavailable_does_not_expose_historical_finance_fallback(): void
    {
        $fetch = new RdServiceFetchResult(
            retriable: true,
            fallbackToAdmin: false,
            enrichment: null,
            httpStatus: 503,
            errorMessage: 'Spoke timeout',
        );

        $orders = $this->createMock(OrderEnrichmentLookupService::class);
        $orders->method('fetchFromSpokes')->with('RD2579')->willReturn($fetch);

        $this->app->instance(OrderEnrichmentLookupService::class, $orders);

        $service = app(HistoricalInvoiceLookupService::class);

        $this->assertFalse($service->shouldExposeHistoricalFinanceFallback('RD2579'));
        $this->assertFalse($service->lookupForCustomer360('RD2579')->canReprint());
    }

    public function test_legacy_order_with_historical_invoice_exposes_finance_fallback(): void
    {
        $enrichment = new RadiumBoxOrderEnrichment(
            legacyOrderStatus: 'Completed',
            invoiceNumber: 'INV6717810',
            customerName: 'Legacy Customer',
            customerPhone: '9999999999',
            customerEmail: 'legacy@example.com',
        );

        $fetch = new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            enrichment: $enrichment,
            httpStatus: 200,
        );

        $orders = $this->createMock(OrderEnrichmentLookupService::class);
        $orders->method('fetchFromSpokes')->with('RD3396720')->willReturn($fetch);

        $this->app->instance(OrderEnrichmentLookupService::class, $orders);

        $service = app(HistoricalInvoiceLookupService::class);

        $this->assertTrue($service->shouldExposeHistoricalFinanceFallback('RD3396720'));
        $this->assertTrue($service->lookupForCustomer360('RD3396720')->canReprint());
    }
}
