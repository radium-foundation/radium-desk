<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use ReflectionMethod;
use Tests\Support\AssertsStatutoryInvoicePdfSerials;
use Tests\TestCase;

/**
 * Permanent regression contract for the verified A4 statutory invoice PDF renderer.
 *
 * Locks layout behavior introduced in bfabb2cb without prescribing future visual redesign.
 */
class StatutoryInvoicePdfRegressionContractTest extends TestCase
{
    use AssertsStatutoryInvoicePdfSerials;

    private const IRN = '632fdabeceaad34a5dc5c6782c0ec471bae2d88f8efb8eb6b82747dba3f919b1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLocationSellerIdentity();
    }

    public function test_contract_locks_a4_dimensions_and_renderer_constants(): void
    {
        $binary = $this->render($this->basePayload($this->shortSerialList(1)));

        $this->assertStatutoryPdfLayoutConstantsLocked();
        $this->assertA4PageDimensions($binary);
    }

    public function test_contract_large_serial_set_uses_annexure_with_zero_page_one_serials(): void
    {
        $serials = $this->shortSerialList(SimplePdfRenderer::MAX_INLINE_SERIALS + 1);
        $binary = $this->render($this->basePayload($serials, withIrn: true));

        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertPage1StructureForAnnexureInvoice($binary, self::IRN, '172621241989060');
        $this->assertAnnexureContainsCompleteSerialPopulation($binary, $serials);
        $this->assertNoDuplicateSerialsInPdf($binary, $serials);
        $this->assertNoOrphanedBlankPages($binary);
    }

    public function test_contract_small_serial_set_renders_inline_only_when_measured_block_fits(): void
    {
        $serials = $this->shortSerialList(SimplePdfRenderer::MAX_INLINE_SERIALS);
        $binary = $this->render($this->basePayload($serials, withIrn: true));

        $this->assertSame(
            SimplePdfRenderer::MAX_INLINE_SERIALS,
            $this->resolveFirstPageSerialCount($this->basePayload($serials, withIrn: true)),
        );
        $this->assertOptionBMainPageOnly($binary, $serials);
        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
    }

    public function test_contract_non_fitting_small_serial_set_is_all_or_nothing_to_annexure(): void
    {
        $serials = $this->longSerialList(SimplePdfRenderer::MAX_INLINE_SERIALS);
        $payload = $this->payloadWithLongProductTable($serials, withIrn: true);

        $this->assertSame(0, $this->resolveFirstPageSerialCount($payload));

        $binary = $this->render($payload);
        $this->assertOptionBWithAnnexure($binary, $serials);
        $this->assertAnnexureContainsCompleteSerialPopulation($binary, $serials);
    }

    public function test_contract_inline_fit_uses_render_driven_measurement_not_character_heuristics(): void
    {
        $renderer = new SimplePdfRenderer;
        $method = new ReflectionMethod(SimplePdfRenderer::class, 'measureUngroupedInlineSerialBlockHeight');
        $method->setAccessible(true);
        $source = (string) file_get_contents($method->getFileName());

        $this->assertStringContainsString('renderNumberedSerialGrid($dummyOps', $source);
        $this->assertStringContainsString(', false)', $source);
        $this->assertStringContainsString('if ($rendered < count($serials))', $source);

        $height = $method->invoke($renderer, $this->longSerialList(3));
        $this->assertGreaterThan(0.0, $height);
    }

    public function test_contract_page_one_footer_remains_anchored_when_annexure_is_required(): void
    {
        $serials = $this->shortSerialList(120);
        $binary = $this->render($this->basePayload($serials, withIrn: true));

        $this->assertMainInvoiceFooterOnFirstPage($binary, self::IRN);
        $this->assertInvoiceClosingPresentBeforeAnnexure($binary, self::IRN, '172621241989060');
        $this->assertStringContainsString(
            'Whether tax is payable on reverse charge basis: No',
            $this->firstMainInvoicePageText($this->extractedPdfText($binary)),
        );
        $this->assertNoClosingOnlyContinuationPage($binary);
    }

    /**
     * @param  list<string>  $serials
     */
    private function basePayload(array $serials, bool $withIrn = false): StatutoryInvoicePdfPayload
    {
        return new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-REGRESSION-CONTRACT',
            issuedAt: '2026-09-23 18:35:54',
            sellerLegalName: 'Phil Technologies (P) Limited',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: '1312, Hemkunt Chambers, Nehru Place, New Delhi 110019',
            sellerState: 'Delhi',
            buyerName: 'Hardware Buyer',
            buyerGstin: '07AAAAA0000A1Z5',
            billingAddress: '1 Test Street, Delhi',
            placeOfSupply: 'Delhi',
            lines: [[
                'description' => 'RBUGR89GPS — RADIUM UGR86 89-NaviC UIDAI Approved GPS for AADHAAR',
                'hsnSac' => '85269190',
                'qty' => max(count($serials), 1),
                'unitPrice' => '1800.00',
                'taxableValue' => '216000.00',
                'gstPercentage' => '18.00%',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '38880.00',
                'taxTotal' => '38880.00',
                'lineTotal' => '254880.00',
                'uqc' => 'PCS',
            ]],
            taxableValue: '216000.00',
            gstRate: '18.00%',
            taxTotal: '38880.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '38880.00',
            invoiceValue: '254880.00',
            serialNumbers: $serials,
            channel: StatutoryInvoiceChannel::DeskPos->value,
            orderId: 'POS-REGRESSION-CONTRACT',
            irn: $withIrn ? self::IRN : null,
            ackNo: $withIrn ? '172621241989060' : null,
            ackDate: $withIrn ? '2026-09-23 18:36:00' : null,
            signedQr: $withIrn ? $this->signedQr() : null,
        );
    }

    /**
     * @param  list<string>  $serials
     */
    private function payloadWithLongProductTable(array $serials, bool $withIrn): StatutoryInvoicePdfPayload
    {
        $lines = [];
        for ($i = 1; $i <= 6; $i++) {
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

        $payload = $this->basePayload($serials, $withIrn);

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: $payload->invoiceNumber,
            issuedAt: $payload->issuedAt,
            sellerLegalName: $payload->sellerLegalName,
            sellerGstin: $payload->sellerGstin,
            sellerAddress: $payload->sellerAddress,
            sellerState: $payload->sellerState,
            buyerName: $payload->buyerName,
            buyerGstin: $payload->buyerGstin,
            billingAddress: $payload->billingAddress,
            placeOfSupply: $payload->placeOfSupply,
            lines: $lines,
            taxableValue: $payload->taxableValue,
            gstRate: $payload->gstRate,
            taxTotal: $payload->taxTotal,
            cgst: $payload->cgst,
            sgst: $payload->sgst,
            igst: $payload->igst,
            invoiceValue: $payload->invoiceValue,
            serialNumbers: $serials,
            channel: $payload->channel,
            orderId: $payload->orderId,
            irn: $payload->irn,
            ackNo: $payload->ackNo,
            ackDate: $payload->ackDate,
            signedQr: $payload->signedQr,
        );
    }

    private function render(StatutoryInvoicePdfPayload $payload): string
    {
        return (new SimplePdfRenderer)->render($payload);
    }

    private function resolveFirstPageSerialCount(StatutoryInvoicePdfPayload $payload): int
    {
        $renderer = new SimplePdfRenderer;
        $method = new ReflectionMethod(SimplePdfRenderer::class, 'resolveFirstPageSerialCount');
        $method->setAccessible(true);

        return (int) $method->invoke($renderer, $payload);
    }

    /**
     * @return list<string>
     */
    private function shortSerialList(int $count): array
    {
        $serials = [];
        for ($i = 1; $i <= $count; $i++) {
            $serials[] = sprintf('109%05d', 50800 + $i);
        }

        return $serials;
    }

    /**
     * @return list<string>
     */
    private function longSerialList(int $count): array
    {
        $serials = [];
        for ($i = 1; $i <= $count; $i++) {
            $serials[] = sprintf(
                'H%05d-MP826D81704%04d-09/26',
                22000 + $i - 1,
                2835 + ($i * 17) % 10000,
            );
        }

        return $serials;
    }

    private function signedQr(): string
    {
        return 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoiZWluaXZvaWNlLXRlc3QtcGF5bG9hZC1maXh0dXJlIn0.dGVzdC1zaWduYXR1cmUtZml4dHVyZS1ub3QtcHJvZHVjdGlvbg';
    }
}
