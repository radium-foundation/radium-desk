<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class StatutoryInvoicePdfSerialCapacityTest extends TestCase
{
    public function test_fifty_serials_with_irn_leave_room_for_page_one_verification_block(): void
    {
        $capacity = $this->firstPageSerialCapacity($this->inv0767146StylePayload());

        $this->assertGreaterThan(0, $capacity);
        $this->assertLessThan(50, $capacity);
    }

    public function test_fifty_one_serials_without_irn_respect_absolute_ceiling(): void
    {
        $capacity = $this->firstPageSerialCapacity($this->hardwarePayload(
            serialCount: 51,
            withIrn: false,
        ));

        $this->assertSame(SimplePdfRenderer::FIRST_PAGE_SERIAL_LIMIT, $capacity);
    }

    public function test_one_hundred_serials_with_irn_split_between_page_one_and_annexure(): void
    {
        $capacity = $this->firstPageSerialCapacity($this->hardwarePayload(
            serialCount: 100,
            withIrn: true,
        ));

        $this->assertGreaterThan(0, $capacity);
        $this->assertLessThan(100, $capacity);
    }

    public function test_multipage_invoice_keeps_six_serials_on_page_one(): void
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

        $capacity = $this->firstPageSerialCapacity(new StatutoryInvoicePdfPayload(
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
        ));

        $this->assertSame(6, $capacity);
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

    private function inv0767146StylePayload(): StatutoryInvoicePdfPayload
    {
        $serials = array_map(
            fn (int $i): string => sprintf('1053%04d', 2000 + $i),
            range(1, 50),
        );

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-0767146',
            issuedAt: '2026-09-18 17:56:00',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            sellerEmail: 'mail@radiumbox.com',
            sellerPhone: '+91-84343 84343',
            buyerName: 'CDSIMER',
            buyerGstin: '29AAAJD1151D1ZS',
            billingAddress: 'Dr. Chandramma Dayananda Sagar Institute of Medical Education & Research, Deverakaggalahalli, Kanakapura Road,Bengaluru South District, Karnataka - 562 112',
            placeOfSupply: 'Karnataka',
            lines: [[
                'description' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner (bundled RD #1119)',
                'hsnSac' => '84716050',
                'qty' => 50,
                'unitPrice' => '2499.00',
                'taxableValue' => '21177.97',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '3812.03',
                'taxTotal' => '3812.03',
                'lineTotal' => '124950.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '21177.97',
            gstRate: '18.00%',
            taxTotal: '3812.03',
            cgst: '0.00',
            sgst: '0.00',
            igst: '3812.03',
            invoiceValue: '124950.00',
            serialNumbers: $serials,
            orderId: 'RDE318516',
            paymentReference: '6864309255',
            paymentMethod: 'cashfree',
            irn: '632fdabeceaad34a5dc5c6782c0ec471bae2d88f8efb8eb6b82747dba3f919b1',
            ackNo: '172621204889923',
            ackDate: '2026-09-18 17:56:00',
            signedQr: 'eyJhbGciOiJFUzI1NiJ9.payload.signature',
        );
    }
}
