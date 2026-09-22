<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class StatutoryInvoicePdfSerialCapacityTest extends TestCase
{
    public function test_ten_or_fewer_serials_use_main_page_only(): void
    {
        $capacity = $this->firstPageSerialCapacity($this->hardwarePayload(serialCount: 10, withIrn: true));

        $this->assertSame(10, $capacity);
        $this->assertSame([], $this->annexureSerials($this->hardwarePayload(serialCount: 10, withIrn: true)));
    }

    public function test_eleven_serials_fit_on_main_page_without_annexure_when_space_allows(): void
    {
        $payload = $this->hardwarePayload(serialCount: 11, withIrn: true);
        $capacity = $this->firstPageSerialCapacity($payload);
        $annexure = $this->annexureSerials($payload);

        $this->assertSame(11, $capacity);
        $this->assertSame([], $annexure);
    }

    public function test_one_hundred_twenty_serials_keep_main_page_cap_and_full_annexure(): void
    {
        $payload = $this->hardwarePayload(serialCount: 120, withIrn: true);
        $capacity = $this->firstPageSerialCapacity($payload);
        $annexure = $this->annexureSerials($payload);

        $this->assertSame(SimplePdfRenderer::MAIN_PAGE_SERIAL_LIMIT, $capacity);
        $this->assertCount(120, $annexure);
        $this->assertSame('SN-001', $annexure[0]);
        $this->assertSame('SN-120', $annexure[119]);
    }

    public function test_multipage_invoice_keeps_six_serials_on_page_one_without_annexure(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = [
                'description' => 'Information technology (IT) consulting & support services line '.$i.' (SAC - 998313) with additional wrapping text for multi-page invoices',
                'hsnSac' => '998314',
                'qty' => 1,
                'unitPrice' => '100.00',
                'taxableValue' => '100.00',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '18.00',
                'taxTotal' => '18.00',
                'lineTotal' => '118.00',
            ];
        }

        $payload = new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-27690',
            issuedAt: '2026-09-07 20:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '27AAICP1128M1Z7',
            sellerAddress: 'G40, Harmony Mall, Link Road, Goregaon, Mumbai 400104',
            sellerState: 'Maharashtra',
            buyerName: 'Long Name Customer',
            buyerGstin: null,
            billingAddress: 'Ward No. 12, Civil Lines, Banda, Uttar Pradesh 210001',
            placeOfSupply: 'Uttar Pradesh',
            lines: $lines,
            taxableValue: '1200.00',
            gstRate: '18.00%',
            taxTotal: '216.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '216.00',
            invoiceValue: '1416.00',
            serialNumbers: ['SN-1', 'SN-2', 'SN-3', 'SN-4', 'SN-5', 'SN-6'],
            sourceId: 'RDE900305',
        );

        $this->assertSame(6, $this->firstPageSerialCapacity($payload));
        $this->assertSame([], $this->annexureSerials($payload));
    }

    /**
     * @return list<string>
     */
    private function annexureSerials(StatutoryInvoicePdfPayload $payload): array
    {
        $renderer = new SimplePdfRenderer;
        $method = new ReflectionMethod(SimplePdfRenderer::class, 'annexureSerials');
        $method->setAccessible(true);

        return $method->invoke($renderer, $payload);
    }

    private function firstPageSerialCapacity(StatutoryInvoicePdfPayload $payload): int
    {
        $renderer = new SimplePdfRenderer;
        $method = new ReflectionMethod(SimplePdfRenderer::class, 'resolveFirstPageSerialCount');
        $method->setAccessible(true);

        return (int) $method->invoke($renderer, $payload);
    }

    private function hardwarePayload(int $serialCount, bool $withIrn): StatutoryInvoicePdfPayload
    {
        $serials = [];
        for ($i = 1; $i <= $serialCount; $i++) {
            $serials[] = sprintf('SN-%03d', $i);
        }

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-CAPACITY',
            issuedAt: '2026-09-11 12:00:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'Mantra MFS 110 L1',
                'hsnSac' => '84716050',
                'qty' => $serialCount,
                'unitPrice' => '100.00',
                'taxableValue' => '10000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '900.00',
                'sgst' => '900.00',
                'igst' => '0.00',
                'taxTotal' => '1800.00',
                'lineTotal' => '11800.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '10000.00',
            gstRate: '18.00%',
            taxTotal: '1800.00',
            cgst: '900.00',
            sgst: '900.00',
            igst: '0.00',
            invoiceValue: '11800.00',
            serialNumbers: $serials,
            irn: $withIrn ? 'issued-irn-token-0001' : null,
            ackNo: $withIrn ? '112233' : null,
            ackDate: $withIrn ? '2026-09-07 18:40:00' : null,
            signedQr: $withIrn ? 'eyJhbGciOiJFUzI1NiJ9.payload.signature' : null,
        );
    }
}
