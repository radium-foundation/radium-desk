<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareCommerceStatutoryInvoiceGuard;
use App\Services\StatutoryInvoice\RadiumBoxServiceCommerceLookupMapper;
use App\Services\StatutoryInvoice\RadiumBoxServiceCommerceSnapshotService;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\BusinessOrderId;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RadiumBoxServiceStatutoryIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private RadiumBoxServiceCommerceSnapshotService $snapshots;

    private StatutoryBillingIssuer $issuer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 18:00:00');

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'channel_ingest.auto_issue_invoice' => false,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'test-radiumbox-spoke-token',
            'order_lookup.spokes.radiumbox_com.accepts' => ['rd', 'rde', 'rb', 'rbp', 'rbx'],
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->snapshots = app(RadiumBoxServiceCommerceSnapshotService::class);
        $this->issuer = app(StatutoryBillingIssuer::class);
        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'superadmin@radium.local']);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_service_commerce_mapper_maps_rb222_fixture(): void
    {
        $lookup = app(RadiumBoxServiceCommerceLookupMapper::class)->map(
            $this->rb222LookupPayload(),
            'RB222',
        );

        $this->assertSame('RB222', $lookup->rdOrderId);
        $this->assertSame('Tamil Nadu', $lookup->billingState);
        $this->assertSame('Tamil Nadu', $lookup->placeOfSupplyState);
        $this->assertSame(507.63, $lookup->taxableValue);
        $this->assertSame(91.37, $lookup->taxTotal);
        $this->assertSame(599.0, $lookup->lineTotal);
        $this->assertSame(18.0, $lookup->gstPercentage);
        $this->assertSame('998314', $lookup->catalogHsnSac);
    }

    public function test_rb_service_snapshot_creates_radiumbox_com_service_commerce_identity(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/rd-orders/RB222' => Http::response($this->rb222LookupPayload()),
        ]);

        $order = $this->deskOrder('RB222');
        $commerce = $this->snapshots->ensureForSupportOrder($order);

        $this->assertSame(StatutoryInvoiceChannel::RadiumBoxCom, $commerce->channel);
        $this->assertSame('RB222', $commerce->source_id);
        $this->assertSame('statutory:radiumbox_com:commerce_order:RB222', $commerce->idempotency_key);
        $this->assertSame('Tamil Nadu', $commerce->billing_state);
        $this->assertSame('Tamil Nadu', $commerce->place_of_supply_state);
        $this->assertSame(507.63, (float) $commerce->taxable_value);
        $this->assertSame(91.37, (float) $commerce->tax_total);
        $this->assertSame(599.0, (float) $commerce->order_value);
        $this->assertSame($order->id, $commerce->support_order_id);
        $this->assertCount(1, $commerce->items);
        $this->assertNull($commerce->items[0]->shipping_line_kind);
        $this->assertSame('998314', $commerce->items[0]->hsn_sac);
        $this->assertStringContainsString('7675286', $commerce->items[0]->description);
    }

    public function test_rb_service_snapshot_is_idempotent(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/rd-orders/RB222' => Http::response($this->rb222LookupPayload()),
        ]);

        $order = $this->deskOrder('RB222');
        $first = $this->snapshots->ensureForSupportOrder($order);
        $second = $this->snapshots->ensureForSupportOrder($order->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, $first->items()->count());
    }

    public function test_missing_billing_state_fails_closed_before_commerce_snapshot(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/rd-orders/RB222' => Http::response($this->rb222LookupPayload(missingBillingState: true)),
        ]);

        $order = $this->deskOrder('RB222');

        try {
            $this->snapshots->ensureForSupportOrder($order);
            $this->fail('Expected missing billing_state to fail closed.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_missing_tax_fields_fail_closed_before_commerce_snapshot(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/rd-orders/RB222' => Http::response($this->rb222LookupPayload(missingTax: true)),
        ]);

        $order = $this->deskOrder('RB222');

        try {
            $this->snapshots->ensureForSupportOrder($order);
            $this->fail('Expected missing tax fields to fail closed.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_rb222_b2c_tamil_nadu_issues_delhi_b2c_inv_671_with_sac_998313(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/rd-orders/RB222' => Http::response($this->rb222LookupPayload()),
        ]);

        $order = $this->deskOrder('RB222');
        $invoice = $this->invoices->issueFromSupportOrder($order, $this->actor);

        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame('statutory:radiumbox_com:commerce_order:RB222', $invoice->idempotency_key);
        $this->assertSame('Tamil Nadu', $invoice->place_of_supply_state);
        $this->assertSame('998313', $invoice->items[0]->hsn_sac);
        $this->assertSame(507.63, (float) $invoice->items[0]->taxable_value);
        $this->assertSame(91.37, (float) $invoice->items[0]->tax_total);
        $this->assertSame(599.0, (float) $invoice->items[0]->line_total);
        $this->assertSame(91.37, (float) $invoice->igst);
        $this->assertSame(0.0, (float) $invoice->cgst);
        $this->assertSame(0.0, (float) $invoice->sgst);

        $binary = app(StatutoryDocumentService::class)->binary($invoice->document);
        $this->assertSame('brand/logo.png', config('branding.logo'));
        $this->assertStringContainsString('/Logo Do', $binary);
        $this->assertStringNotContainsString('radiumbox-logo-white.png', $binary);
        $this->assertCount(1, $invoice->items);
    }

    public function test_rb222_fixture_pdf_omits_unselected_regular_companion_line(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/rd-orders/RB222' => Http::response($this->rb222LookupPayload()),
        ]);

        $deskOrder = $this->deskOrder('RB222');
        $commerce = $this->snapshots->ensureForSupportOrder($deskOrder);
        $commerce->items()->create([
            'line_no' => 2,
            'description' => 'RD Technical Support — included',
            'variant' => 'regular',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($commerce->fresh(['items']), $this->actor);
        $pdf = $this->pdfText(app(StatutoryDocumentService::class)->binary($invoice->document));

        $this->assertCount(1, $invoice->items);
        $this->assertSame(599.0, (float) $invoice->invoice_value);
        $this->assertStringContainsString('1 Year Unlimited', (string) $invoice->items[0]->description);
        $this->assertStringNotContainsString('RD Technical Support - included', $pdf);
        $this->assertStringNotContainsString('Not Required', $pdf);
    }

    public function test_repeated_rb_statutory_issuance_is_idempotent(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/rd-orders/RB222' => Http::response($this->rb222LookupPayload()),
        ]);

        $order = $this->deskOrder('RB222');
        $first = $this->invoices->issueFromSupportOrder($order, $this->actor);
        $second = $this->invoices->issueFromSupportOrder($order->fresh(), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, CommerceOrder::query()->count());
    }

    public function test_rd_routing_remains_rdservice_in(): void
    {
        $this->assertFalse(BusinessOrderId::isRadiumBoxService('RD441'));
        $this->assertTrue(BusinessOrderId::isRadiumBoxService('RB222'));
        $this->assertFalse(BusinessOrderId::isRadiumBoxService('RBP222'));
        $this->assertFalse(BusinessOrderId::isRadiumBoxService('RBX3511545'));
    }

    public function test_rbp_hardware_commerce_still_requires_serial_path(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-RBP1',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RBP222',
            'source_order_id' => 'RBP222',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP222',
            'payload_hash' => hash('sha256', 'rbp222'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'place_of_supply_state' => 'Madhya Pradesh',
            'billing_state' => 'Madhya Pradesh',
            'taxable_value' => 3303.39,
            'tax_total' => 594.61,
            'order_value' => 3898,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Hardware item',
            'hsn_sac' => '84716050',
            'shipping_line_kind' => 'physical_merchandise',
            'qty' => 1,
            'unit_price' => 3303.39,
            'gst_percentage' => 18,
            'taxable_value' => 3303.39,
            'tax_total' => 594.61,
            'line_total' => 3898,
        ]);

        $this->assertTrue(app(HardwareCommerceStatutoryInvoiceGuard::class)->requiresHardwareSerialPath($order->fresh(['items'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function rb222LookupPayload(bool $missingBillingState = false, bool $missingTax = false): array
    {
        $billingState = $missingBillingState ? null : 'Tamil Nadu';
        $taxable = $missingTax ? null : 507.63;
        $tax = $missingTax ? null : 91.37;
        $total = $missingTax ? null : 599.0;
        $rate = $missingTax ? null : 18.0;

        return [
            'status' => 200,
            'message' => 'OK',
            'data' => [
                'correlation' => [
                    'rdorderid' => 'RB222',
                    'customer_order_id' => 'RB222',
                    'cashfree_order_id' => 'RB222',
                    'orders_id' => null,
                ],
                'snapshot' => [
                    'rdorderid' => 'RB222',
                    'customer_name' => 'IsmailI',
                    'email' => 'ismail@dhanhind.com',
                    'phone' => '8608621002',
                    'serial_number' => '7675286',
                    'product' => 'Mantra MIS100',
                    'rd_service' => '1 Year Unlimited',
                    'order_date' => '2026-09-17 15:56:31',
                ],
                'rd_order' => [
                    'rdorderid' => 'RB222',
                    'product_name' => 'Mantra MIS100',
                    'rd_service_name' => '1 Year Unlimited',
                    'serial_no' => '7675286',
                    'paid_amount' => '599',
                ],
                'service_commerce' => [
                    'rdorderid' => 'RB222',
                    'billing_state' => $billingState,
                    'place_of_supply_state' => $billingState,
                    'district' => 'Chennai',
                    'billing_address' => 'Thousand light chennai, Chennai, Tamil Nadu, 600009',
                    'billing_address_structured' => [
                        'line1' => 'Thousand light chennai',
                        'city' => 'Chennai',
                        'state' => $billingState,
                        'pincode' => '600009',
                    ],
                    'gst_no' => null,
                    'taxable_value' => $taxable,
                    'tax_total' => $tax,
                    'line_total' => $total,
                    'gst_percentage' => $rate,
                    'catalog_hsn_sac' => '998314',
                    'service_description' => 'Information Technology (IT) Consulting & Support Services (SAC - 998313) - (Sr. No. 7675286) - 1 Year Unlimited',
                    'ordered_at' => '2026-09-17 15:56:31',
                ],
                'lines' => [],
            ],
        ];
    }

    private function deskOrder(string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7675286',
            'product_name' => 'Mantra MIS100',
            'device_model' => 'MIS 100',
            'service_history' => ['1 Year Unlimited'],
            'cashfree_payment_id' => '6513599371',
            'gateway_order_id' => '6939017267',
            'gateway_payment_id' => '6513599371',
            'payment_amount' => 599,
            'payment_method' => 'CREDIT_CARD',
            'payment_date' => '2026-09-17 16:00:34',
            'transaction_id' => 'f09e847f9e814ded',
            'customer_name' => 'IsmailI',
            'customer_email' => 'ismail@dhanhind.com',
            'customer_phone' => '8608621002',
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function pdfText(string $binary): string
    {
        if (preg_match_all('/\(([^()]*)\)/', $binary, $matches)) {
            return implode('', $matches[1]);
        }

        return $binary;
    }
}
