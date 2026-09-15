<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceUqcMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class EInvoiceInputReadinessTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    public function test_delhi_seller_pin_and_loc_from_owner_verified_defaults(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice();

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('110019', $payload->seller['pin']);
        $this->assertSame('New Delhi', $payload->seller['location']);
        $this->assertNotNull($payload->seller['address']);
        $this->assertNotContains('missing_seller_pin', $payload->gaps);
        $this->assertNotContains('missing_seller_loc', $payload->gaps);
    }

    public function test_mumbai_seller_pin_and_loc_from_owner_verified_defaults(): void
    {
        $this->configureIssuer('mumbai', '27AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice(['seller_gstin' => '27AAICP1128M1Z9']);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('400104', $payload->seller['pin']);
        $this->assertSame('Mumbai', $payload->seller['location']);
        $this->assertNotContains('missing_seller_pin', $payload->gaps);
        $this->assertNotContains('missing_seller_loc', $payload->gaps);
    }

    public function test_missing_seller_pin_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        config(['statutory_invoices.location_series.locations.delhi.pin' => '']);
        $invoice = $this->makeTaxInvoice();

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('missing_seller_pin', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_missing_seller_location_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        config(['statutory_invoices.location_series.locations.delhi.loc' => '']);
        $invoice = $this->makeTaxInvoice();

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('missing_seller_loc', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_unmapped_seller_gstin_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice(['seller_gstin' => '29AAICP1128M1Z9']);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('missing_seller_pin', $payload->gaps);
        $this->assertContains('missing_seller_loc', $payload->gaps);
        $this->assertContains('missing_seller_address', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_commerce_complete_structured_address_is_accepted(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice();
        $this->attachCommerce($invoice, [
            'line1' => '1 Test Street',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh(['items']));

        $this->assertSame('110001', $payload->buyer['pin']);
        $this->assertSame('New Delhi', $payload->buyer['location']);
        $this->assertSame('07', $payload->buyer['pos_code']);
        $this->assertNotContains('missing_buyer_pin', $payload->gaps);
        $this->assertNotContains('missing_buyer_loc', $payload->gaps);
        $this->assertSame('OTH', $payload->items[0]['unit']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
    }

    public function test_commerce_missing_pin_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice();
        $this->attachCommerce($invoice, [
            'line1' => '1 Test Street',
            'city' => 'New Delhi',
            'state' => 'Delhi',
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh(['items']));

        $this->assertContains('missing_buyer_pin', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_commerce_missing_city_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice();
        $this->attachCommerce($invoice, [
            'line1' => '1 Test Street',
            'state' => 'Delhi',
            'pincode' => '110001',
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh(['items']));

        $this->assertContains('missing_buyer_loc', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_commerce_invalid_state_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice();
        $this->attachCommerce($invoice, [
            'line1' => '1 Test Street',
            'city' => 'New Delhi',
            'state' => 'Not A State',
            'pincode' => '110001',
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh(['items']));

        $this->assertContains('invalid_buyer_state', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_pos_unstructured_billing_string_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice([
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'billing_address' => '1 Counter Street, Delhi',
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('missing_buyer_pin', $payload->gaps);
        $this->assertContains('missing_buyer_loc', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_pos_in_memory_structured_snapshot_is_accepted_without_writing_schema(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice([
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'billing_address' => '1 Counter Street, Delhi',
        ]);
        $sale = new InventorySale;
        $sale->setAttribute('billing_address_structured', [
            'line1' => '1 Counter Street',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ]);
        $invoice->setRelation('inventorySale', $sale);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('110001', $payload->buyer['pin']);
        $this->assertSame('New Delhi', $payload->buyer['location']);
        $this->assertNotContains('missing_buyer_pin', $payload->gaps);
        $this->assertFalse($sale->exists);
    }

    public function test_pos_structured_snapshot_missing_pin_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice([
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
        ]);
        $sale = new InventorySale;
        $sale->setAttribute('billing_address_structured', [
            'line1' => '1 Counter Street',
            'city' => 'New Delhi',
            'state' => 'Delhi',
        ]);
        $invoice->setRelation('inventorySale', $sale);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('missing_buyer_pin', $payload->gaps);
    }

    public function test_pos_structured_snapshot_missing_city_fails_closed(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice([
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
        ]);
        $sale = new InventorySale;
        $sale->setAttribute('billing_address_structured', [
            'line1' => '1 Counter Street',
            'state' => 'Delhi',
            'pincode' => '110001',
        ]);
        $invoice->setRelation('inventorySale', $sale);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('missing_buyer_loc', $payload->gaps);
    }

    public function test_invoice_structured_snapshot_is_preferred_over_source_sale(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice([
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'billing_address_structured' => [
                'line1' => 'Invoice Lane',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
        ]);
        $sale = new InventorySale;
        $sale->setAttribute('billing_address_structured', [
            'line1' => 'Later sale lane',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001',
        ]);
        $invoice->setRelation('inventorySale', $sale);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('110001', $payload->buyer['pin']);
        $this->assertSame('New Delhi', $payload->buyer['location']);
    }

    public function test_stored_statutory_uqc_is_accepted_including_pcs(): void
    {
        $accepted = $this->makeTaxInvoice([], ['uqc' => 'NOS']);
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($accepted);
        $this->assertSame('NOS', $payload->items[0]['unit']);
        $this->assertNotContains('missing_uqc', $payload->gaps);

        $pcs = $this->makeTaxInvoice([], ['uqc' => 'pcs']);
        $pcsPayload = app(EInvoiceIrnPayloadMapper::class)->map($pcs);
        $this->assertSame('PCS', $pcsPayload->items[0]['unit']);
        $this->assertNotContains('unsupported_uqc', $pcsPayload->gaps);

        $rejected = $this->makeTaxInvoice([], ['uqc' => 'widget']);
        $bad = app(EInvoiceIrnPayloadMapper::class)->map($rejected);
        $this->assertNull($bad->items[0]['unit']);
        $this->assertContains('unsupported_uqc', $bad->gaps);
        $this->assertNotSame('PCS', $bad->items[0]['unit']);
        $this->assertNotSame('NOS', $bad->items[0]['unit']);
    }

    public function test_uqc_missing_on_statutory_lines_fails_closed_without_pcs_or_nos_fallback(): void
    {
        $invoice = $this->makeHardwareTaxInvoice([], ['uqc' => null]);
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNull($payload->items[0]['unit']);
        $this->assertContains('missing_uqc', $payload->gaps);
        $this->assertNotSame('pcs', $payload->items[0]['unit']);
        $this->assertNotSame('NOS', $payload->items[0]['unit']);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_uqc_mapper_accepts_stored_official_code_and_rejects_unknown(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => 'KGS', 'gap' => null], $mapper->resolve('kgs'));
        $this->assertSame(['code' => 'NOS', 'gap' => null], $mapper->resolve('NOS'));
        $this->assertSame(['code' => 'PCS', 'gap' => null], $mapper->resolve('pcs'));
        $this->assertSame(['code' => null, 'gap' => 'unsupported_uqc'], $mapper->resolve('widget'));
        $this->assertSame(['code' => null, 'gap' => 'unsupported_uqc'], $mapper->resolve('GGR'));
        $this->assertSame(['code' => null, 'gap' => 'missing_uqc'], $mapper->resolve(null));
        $this->assertSame(['code' => null, 'gap' => 'missing_uqc'], $mapper->resolve(''));
    }

    public function test_mapping_does_not_rewrite_historical_invoice_or_einvoice_rows(): void
    {
        $invoice = $this->makeTaxInvoice(['billing_address' => 'Historical address keep']);
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'none',
            'irn' => 'keep-historical-irn-token-00000000000000000000000000000001',
            'status' => 'submitted',
        ]);
        $before = [
            'billing_address' => $invoice->billing_address,
            'taxable_value' => (string) $invoice->taxable_value,
            'cgst' => (string) $invoice->cgst,
        ];

        app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $fresh = StatutoryInvoice::query()->findOrFail($invoice->id);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame($before['billing_address'], $fresh->billing_address);
        $this->assertNull($fresh->billing_address_structured);
        $this->assertNull($fresh->items->first()?->uqc);
        $this->assertSame($before['taxable_value'], (string) $fresh->taxable_value);
        $this->assertSame($before['cgst'], (string) $fresh->cgst);
        $this->assertSame('keep-historical-irn-token-00000000000000000000000000000001', $record?->irn);
    }

    private function configureIssuer(string $location, string $gstin): void
    {
        config([
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.'.$location.'.gstin' => $gstin,
        ]);
    }

    /**
     * @param  array<string, string>  $structured
     */
    public function test_billing_state_differs_from_gstin_state_fails_closed_before_generate(): void
    {
        $this->configureIssuer('delhi', '07AAICP1128M1Z9');
        $invoice = $this->makeTaxInvoice([
            'buyer_gstin' => '29AAICA3918J1ZE',
            'billing_address_structured' => [
                'line1' => 'Vpo Budhi Bawal',
                'city' => 'Alwar',
                'state' => 'Rajasthan',
                'pincode' => '301707',
            ],
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('buyer_pin_gstin_state_mismatch', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    private function attachCommerce(StatutoryInvoice $invoice, array $structured): void
    {
        CommerceOrder::query()->create([
            'order_no' => 'CO-'.$invoice->source_id,
            'channel' => $invoice->channel,
            'source_type' => 'commerce_order',
            'source_id' => $invoice->source_id,
            'source_order_id' => $invoice->source_id,
            'idempotency_key' => 'statutory:einvoice:commerce:'.$invoice->id,
            'payload_hash' => hash('sha256', 'einvoice-'.$invoice->id),
            'status' => CommerceOrderStatus::Invoiced,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer Industries',
            'buyer_gstin' => $invoice->buyer_gstin,
            'billing_address' => $invoice->billing_address,
            'billing_address_structured' => $structured,
            'place_of_supply_state' => $invoice->place_of_supply_state,
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'statutory_invoice_id' => $invoice->id,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
        ]);
    }
}
