<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceUqcMapper;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksNicPayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class EInvoiceGeneratePayloadPrepTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    public function test_statutory_line_uqc_is_used_when_populated(): void
    {
        $this->configureIssuer();
        $this->catalogProduct('RBMFS110L1', 'PCS');
        $invoice = $this->invoice1062(['uqc' => 'NOS']);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('NOS', $payload->items[0]['unit']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
        $this->assertSame('NOS', StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->value('uqc'));
    }

    public function test_null_line_uqc_resolves_unambiguous_catalog_pcs_without_mutating_the_line(): void
    {
        $this->configureIssuer();
        $this->catalogProduct('RBMFS110L1', 'PCS');
        $this->mapBoxModel(946, 'RBMFS110L1');
        $invoice = $this->invoice1062(['uqc' => null]);
        $updatedAt = StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->value('updated_at');

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('PCS', $payload->items[0]['unit']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
        $this->assertNull(StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->value('uqc'));
        $this->assertSame(
            (string) $updatedAt,
            (string) StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->value('updated_at'),
        );
    }

    public function test_null_line_and_null_catalog_uqc_fails_closed(): void
    {
        $this->configureIssuer();
        $this->catalogProduct('RBMFS110L1', null);
        $this->mapBoxModel(946, 'RBMFS110L1');
        $invoice = $this->invoice1062(['uqc' => null]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNull($payload->items[0]['unit']);
        $this->assertContains('missing_uqc', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
        $this->assertNull(StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->value('uqc'));
    }

    public function test_invalid_catalog_uqc_fails_closed(): void
    {
        $this->configureIssuer();
        $this->catalogProduct('RBMFS110L1', 'WIDGET');
        $this->mapBoxModel(946, 'RBMFS110L1');
        $invoice = $this->invoice1062(['uqc' => null]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNull($payload->items[0]['unit']);
        $this->assertContains('unsupported_uqc', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
        $this->assertNull(StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->value('uqc'));
    }

    public function test_invoice_1062_generate_body_uses_assessable_unit_price_and_pcs(): void
    {
        $this->configureIssuer();
        $this->catalogProduct('RBMFS110L1', 'PCS');
        $this->mapBoxModel(946, 'RBMFS110L1');
        $invoice = $this->invoice1062(['uqc' => null]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);
        $body = (new WhitebooksNicPayloadFactory)->generateBody($payload);

        $this->assertTrue($payload->isSubmittable());
        $this->assertIsArray($body);
        $this->assertSame('B2B', $body['TranDtls']['SupTyp']);
        $this->assertSame('INV', $body['DocDtls']['Typ']);
        $this->assertSame('84716050', $body['ItemList'][0]['HsnCd']);
        $this->assertSame(2.0, $body['ItemList'][0]['Qty']);
        $this->assertSame('PCS', $body['ItemList'][0]['Unit']);
        $this->assertSame(2160.17, $body['ItemList'][0]['UnitPrice']);
        $this->assertSame(4320.34, $body['ItemList'][0]['AssAmt']);
        $this->assertSame(4320.34, $body['ItemList'][0]['TotAmt']);
        $this->assertSame(18.0, $body['ItemList'][0]['GstRt']);
        $this->assertSame(777.66, $body['ItemList'][0]['IgstAmt']);
        $this->assertSame(5098.00, $body['ItemList'][0]['TotItemVal']);
        $this->assertSame(5098.00, $body['ValDtls']['TotInvVal']);
        $this->assertEqualsWithDelta(4320.34, $body['ItemList'][0]['UnitPrice'] * $body['ItemList'][0]['Qty'], 0.001);
        $this->assertEqualsWithDelta(5098.00, 4320.34 + 777.66, 0.001);
        $this->assertNotSame(2549.00, $body['ItemList'][0]['UnitPrice']);
        $this->assertSame('2549.00', $payload->items[0]['unit_price']);
        $this->assertNull(StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->value('uqc'));
        $this->assertArrayNotHasKey('EwbDtls', $body);
    }

    public function test_serialized_hardware_without_catalog_uqc_does_not_default_pcs(): void
    {
        $this->configureIssuer();
        InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'Mantra MFS 110 L1',
            'hsn_code' => '84716050',
            'uqc' => null,
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $invoice = $this->makeTaxInvoice(
            ['channel' => StatutoryInvoiceChannel::RadiumBoxCom],
            ['sku' => 'RBMFS110L1', 'hsn_sac' => '84716050', 'uqc' => null],
        );

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNull($payload->items[0]['unit']);
        $this->assertContains('missing_uqc', $payload->gaps);
    }

    public function test_reconciled_mapper_accepts_pcs(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => 'PCS', 'gap' => null], $mapper->resolve('PCS'));
        $this->assertContains('PCS', EInvoiceUqcMapper::codes());
    }

    private function configureIssuer(): void
    {
        config([
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.pin' => '110019',
            'statutory_invoices.location_series.locations.delhi.loc' => 'New Delhi',
        ]);
    }

    private function catalogProduct(string $sku, ?string $uqc): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Mantra MFS 110 L1 Single Fingerprint Biometric Scanner',
            'hsn_code' => '84716050',
            'uqc' => $uqc,
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    private function mapBoxModel(int $modelId, string $catalogSku): void
    {
        $product = InventoryProduct::query()->where('sku', $catalogSku)->firstOrFail();
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => $modelId,
            'inventory_product_id' => $product->id,
            'catalog_sku' => $catalogSku,
            'channel_sku' => (string) $modelId,
            'notes' => 'P-189 payload fixture',
        ]);
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     */
    private function invoice1062(array $itemOverrides = []): StatutoryInvoice
    {
        return $this->makeTaxInvoice(
            [
                'invoice_number' => 'INV-076746',
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_id' => 'RDE318400',
                'seller_gstin' => '07AAICP1128M1Z9',
                'seller_name' => 'Phil Technologies (P) Limited',
                'buyer_name' => 'NABAJAT MAHALA',
                'buyer_gstin' => '21CHNPS8997L1Z7',
                'billing_address' => 'TARINI MARKET COMPLEX,, near power house, Bhadrak, Odisha, 756100',
                'billing_address_structured' => [
                    'line1' => 'TARINI MARKET COMPLEX,',
                    'line2' => 'near power house',
                    'city' => 'Bhadrak',
                    'state' => 'Odisha',
                    'pincode' => '756100',
                ],
                'place_of_supply_state' => 'Odisha',
                'taxable_value' => '4320.34',
                'discount' => '0.00',
                'tax_total' => '777.66',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '777.66',
                'rounding' => '0.00',
                'invoice_value' => '5098.00',
                'issued_at' => '2026-09-10 14:21:29',
            ],
            array_merge([
                'sku' => '946',
                'description' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner (bundled RD #1119)',
                'hsn_sac' => '84716050',
                'uqc' => null,
                'qty' => 2,
                'unit_price' => '2549.00',
                'gst_percentage' => '18.00',
                'taxable_value' => '4320.34',
                'tax_total' => '777.66',
                'cgst' => '0.00',
                'sgst' => '0.00',
                'igst' => '777.66',
                'line_total' => '5098.00',
            ], $itemOverrides),
        );
    }
}
