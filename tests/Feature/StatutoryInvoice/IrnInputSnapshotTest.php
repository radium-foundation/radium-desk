<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceUqcMapper;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class IrnInputSnapshotTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->actor->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'channel_ingest.auto_issue_invoice' => false,
        ]);
    }

    public function test_product_uqc_is_stored_when_explicitly_set(): void
    {
        $this->actingAs($this->actor)
            ->post(route('inventory.products.store'), [
                'sku' => 'UQC-NOS-1',
                'name' => 'Numbers unit product',
                'gst_percentage' => 18,
                'unit_price' => 100,
                'uqc' => 'NOS',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('NOS', InventoryProduct::query()->where('sku', 'UQC-NOS-1')->value('uqc'));

        $this->actingAs($this->actor)
            ->post(route('inventory.products.store'), [
                'sku' => 'UQC-PCS-1',
                'name' => 'Pieces unit product',
                'gst_percentage' => 18,
                'unit_price' => 100,
                'uqc' => 'pcs',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('PCS', InventoryProduct::query()->where('sku', 'UQC-PCS-1')->value('uqc'));
    }

    public function test_invalid_product_uqc_is_rejected(): void
    {
        $this->actingAs($this->actor)
            ->post(route('inventory.products.store'), [
                'sku' => 'UQC-BAD-1',
                'name' => 'Invalid unit product',
                'gst_percentage' => 18,
                'unit_price' => 100,
                'uqc' => 'GGR',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('uqc');

        $this->assertSame(0, InventoryProduct::query()->count());

        $this->actingAs($this->actor)
            ->post(route('inventory.products.store'), [
                'sku' => 'UQC-BAD-2',
                'name' => 'Arbitrary unit product',
                'gst_percentage' => 18,
                'unit_price' => 100,
                'uqc' => 'widget',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('uqc');
    }

    public function test_missing_uqc_is_rejected_for_irn_without_fallback(): void
    {
        $product = $this->product();
        $sale = $this->completeB2cSale($product);
        $invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale, $this->actor);

        $this->assertNull($invoice->items->first()?->uqc);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);
        $this->assertNull($payload->items[0]['unit']);
        $this->assertContains('missing_uqc', $payload->gaps);
        $this->assertNotSame('pcs', $payload->items[0]['unit']);
        $this->assertNotSame('NOS', $payload->items[0]['unit']);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_valid_product_uqc_is_copied_to_statutory_item_and_later_catalog_change_does_not_alter_snapshot(): void
    {
        $product = $this->product(['uqc' => 'NOS']);
        $sale = $this->completeB2cSale($product, 'uqc-snap-1');
        $invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale, $this->actor);

        $this->assertSame('NOS', $invoice->items->first()?->uqc);

        $product->update(['uqc' => 'KGS']);
        $this->assertSame('KGS', $product->fresh()->uqc);
        $this->assertSame('NOS', $invoice->fresh('items')->items->first()?->uqc);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh('items'));
        $this->assertSame('NOS', $payload->items[0]['unit']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
    }

    public function test_commerce_uqc_and_structured_billing_are_snapshotted_at_mint(): void
    {
        $product = $this->product(['uqc' => 'KGS']);
        $order = $this->commerceOrder([
            'uqc' => 'NOS',
            'product_id' => $product->id,
            'billing' => [
                'line1' => '1 Commerce Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
        ]);

        $invoice = app(StatutoryInvoiceService::class)->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('NOS', $invoice->items->first()?->uqc);
        $this->assertSame('New Delhi', $invoice->billing_address_structured['city'] ?? null);
        $this->assertSame('110001', $invoice->billing_address_structured['pincode'] ?? null);

        $product->update(['uqc' => 'BOX']);
        $order->items()->update(['uqc' => 'BOX']);
        $this->assertSame('NOS', $invoice->fresh('items')->items->first()?->uqc);
        $this->assertSame('New Delhi', $invoice->fresh()->billing_address_structured['city'] ?? null);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh('items'));
        $this->assertSame('NOS', $payload->items[0]['unit']);
        $this->assertSame('110001', $payload->buyer['pin']);
        $this->assertSame('New Delhi', $payload->buyer['location']);
    }

    public function test_commerce_catalog_uqc_is_used_when_line_uqc_is_absent(): void
    {
        $product = $this->product(['uqc' => 'UNT', 'hsn_code' => '84716050']);
        $order = $this->commerceOrder([
            'uqc' => null,
            'product_id' => $product->id,
            'hsn_sac' => '84716050',
            'description' => 'Mantra MFS 110 L1',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'billing' => [
                'line1' => '1 Commerce Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
        ]);

        $invoice = app(StatutoryInvoiceService::class)->issueFromCommerceOrder($order, $this->actor);
        $this->assertSame('UNT', $invoice->items->first()?->uqc);
    }

    public function test_commerce_incomplete_address_fails_irn_readiness(): void
    {
        $order = $this->commerceOrder([
            'billing' => [
                'line1' => '1 Commerce Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
            ],
        ]);
        $invoice = app(StatutoryInvoiceService::class)->issueFromCommerceOrder($order, $this->actor);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh('items'));
        $this->assertContains('missing_buyer_pin', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_pos_b2b_structured_billing_is_snapshotted_onto_the_statutory_invoice(): void
    {
        $product = $this->product(['uqc' => 'NOS']);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branch, 5, $this->actor);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'B2B Buyer', 'phone' => '9000000201'],
            lines: [['product_id' => $product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: [
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'billing_address' => '1 Counter Street',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
                'place_of_supply_state' => 'Delhi',
            ],
        );

        $this->assertSame('New Delhi', $sale->billing_address_structured['city'] ?? null);
        $this->assertSame('Delhi', $sale->billing_address_structured['state'] ?? null);
        $this->assertSame('110001', $sale->billing_address_structured['pincode'] ?? null);

        $invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale, $this->actor);
        $this->assertSame('NOS', $invoice->items->first()?->uqc);
        $this->assertSame('New Delhi', $invoice->billing_address_structured['city'] ?? null);
        $this->assertSame('110001', $invoice->billing_address_structured['pincode'] ?? null);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh('items'));
        $this->assertSame('110001', $payload->buyer['pin']);
        $this->assertSame('New Delhi', $payload->buyer['location']);
        $this->assertSame('NOS', $payload->items[0]['unit']);
    }

    public function test_commerce_ingest_preserves_valid_uqc_and_rejects_invalid(): void
    {
        $valid = $this->ingestPayload('RD-UQC-1');
        $valid['lines'][0]['uqc'] = 'nos';
        app(ChannelIngestService::class)->ingest($valid, StatutoryInvoiceChannel::RdServiceIn);

        $this->assertSame('NOS', CommerceOrder::query()->where('source_id', 'RD-UQC-1')->first()?->items->first()?->uqc);

        $invalid = $this->ingestPayload('RD-UQC-2');
        $invalid['lines'][0]['uqc'] = 'GGR';
        try {
            app(ChannelIngestService::class)->ingest($invalid, StatutoryInvoiceChannel::RdServiceIn);
            $this->fail('Invalid UQC must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.uqc', $exception->errors());
        }

        $this->assertNull(CommerceOrder::query()->where('source_id', 'RD-UQC-2')->first());
    }

    public function test_historical_invoices_and_irns_are_not_backfilled(): void
    {
        $historical = $this->makeTaxInvoice(['billing_address' => 'Historical address keep']);
        EInvoiceRecord::query()->create([
            'invoice_id' => $historical->id,
            'provider' => 'none',
            'irn' => 'keep-historical-irn-token-00000000000000000000000000000002',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
        $before = [
            'billing_address' => $historical->billing_address,
            'billing_address_structured' => $historical->billing_address_structured,
            'uqc' => $historical->items->first()?->uqc,
            'irn' => 'keep-historical-irn-token-00000000000000000000000000000002',
        ];

        $product = $this->product(['uqc' => 'NOS']);
        $sale = $this->completeB2cSale($product, 'hist-new-1');
        app(StatutoryInvoiceService::class)->issueFromPosSale($sale, $this->actor);
        app(EInvoiceIrnPayloadMapper::class)->map($historical);

        $fresh = StatutoryInvoice::query()->findOrFail($historical->id);
        $record = EInvoiceRecord::query()->where('invoice_id', $historical->id)->first();
        $this->assertSame($before['billing_address'], $fresh->billing_address);
        $this->assertNull($fresh->billing_address_structured);
        $this->assertNull($fresh->items->first()?->uqc);
        $this->assertSame($before['irn'], $record?->irn);
        $this->assertSame($before['uqc'], $fresh->items->first()?->uqc);
    }

    public function test_uqc_mapper_has_no_pcs_or_nos_fallback_for_missing_or_unknown(): void
    {
        $mapper = new EInvoiceUqcMapper;
        $this->assertSame(['code' => 'PCS', 'gap' => null], $mapper->resolve('pcs'));
        $this->assertNull($mapper->snapshot(null, null));
        $this->assertNull($mapper->snapshot('widget', 'NOS'));
        $this->assertSame('NOS', $mapper->snapshot(null, 'NOS'));
        $this->assertContains('PCS', EInvoiceUqcMapper::codes());
        $this->assertNotContains('GGR', EInvoiceUqcMapper::codes());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): InventoryProduct
    {
        return InventoryProduct::query()->create(array_merge([
            'sku' => 'IRN-UQC-'.uniqid(),
            'name' => 'IRN input product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function completeB2cSale(InventoryProduct $product, string $phoneSuffix = '0100'): InventorySale
    {
        app(InventoryStockService::class)->stockInQuantity($product, $this->branch, 5, $this->actor);

        return app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '90000'.$phoneSuffix],
            lines: [['product_id' => $product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );
    }

    /**
     * @param  array{uqc?: ?string, product_id?: int|null, billing?: array<string, string>}  $overrides
     */
    private function commerceOrder(array $overrides = []): CommerceOrder
    {
        $sourceId = 'CO-IRN-'.uniqid();
        $billing = $overrides['billing'] ?? [
            'line1' => '1 Commerce Street',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ];

        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => $overrides['channel'] ?? StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_net:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => $billing['line1'] ?? null,
            'billing_state' => $billing['state'] ?? null,
            'billing_address_structured' => $billing,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => $overrides['description'] ?? 'Information technology (IT) consulting & support services',
            'hsn_sac' => $overrides['hsn_sac'] ?? '998313',
            'uqc' => $overrides['uqc'] ?? null,
            'product_id' => $overrides['product_id'] ?? null,
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        return $order->fresh(['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function ingestPayload(string $sourceId): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'customer' => [
                'name' => 'Walk-in',
                'phone' => '9000000001',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'RADium Desk',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'RD Service',
                'sku' => 'RD-SVC',
                'qty' => 1,
                'unit_price' => 100,
                'hsn_sac' => '998313',
                'gst_percentage' => 18,
                'taxable_value' => 100,
                'tax_total' => 18,
                'line_total' => 118,
            ]],
        ];
    }
}
