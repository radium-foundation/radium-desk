<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstB2cConversionStatus;
use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\EInvoiceRecord;
use App\Models\User;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstB2cConversionService;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommerceOrderBuyerGstProductB2cIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 12:00:00');

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
            'statutory_invoices.invoice_scope_starts_at' => '2026-09-01 00:00:00',
            'statutory_invoices.buyer_gstin_verification.enabled' => true,
            'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => true,
            'channel_ingest.auto_issue_invoice' => false,
            'radiumbox.statutory_receipt_publish.schedule_on_issue' => false,
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_product_with_approved_auto_b2c_mints_blank_buyer_gstin(): void
    {
        $order = $this->productOrderWithInactiveVerification();

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame('07AAICB5215F2ZX', $order->fresh()->buyer_gstin);
    }

    public function test_product_b2c_links_conversion_to_issued_invoice(): void
    {
        $order = $this->productOrderWithInactiveVerification();
        $conversion = CommerceOrderBuyerGstB2cConversion::query()
            ->where('commerce_order_id', $order->id)
            ->firstOrFail();

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $conversion->refresh();
        $this->assertSame($invoice->id, $conversion->statutory_invoice_id);
        $this->assertSame('07AAICB5215F2ZX', $conversion->submitted_gstin);
    }

    public function test_product_b2c_invoice_is_not_irn_eligible(): void
    {
        $order = $this->productOrderWithInactiveVerification();
        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $result = app(EInvoiceEligibility::class)->evaluate($invoice->fresh());
        $this->assertFalse($result->eligible);
        $this->assertSame('b2c_not_eligible', $result->reason);

        $this->invoices->queueEinvoiceIfEligible($invoice->fresh());
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
    }

    public function test_product_b2b_without_conversion_preserves_buyer_gstin(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.enabled' => false]);
        $order = $this->productOrder([
            'buyer_gstin' => '09AAJFV1437D1Z7',
            'billing_state' => 'Uttar Pradesh',
            'place_of_supply_state' => 'Uttar Pradesh',
            'billing_address_structured' => ['state' => 'Uttar Pradesh', 'city' => 'Lucknow', 'pincode' => '226001'],
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame('09AAJFV1437D1Z7', $invoice->buyer_gstin);
    }

    public function test_service_b2b_without_conversion_preserves_buyer_gstin(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.enabled' => false]);
        $order = $this->serviceOrder([
            'buyer_gstin' => '09AAJFV1437D1Z7',
            'billing_state' => 'Uttar Pradesh',
            'place_of_supply_state' => 'Uttar Pradesh',
            'billing_address_structured' => ['state' => 'Uttar Pradesh', 'city' => 'Lucknow', 'pincode' => '226001'],
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame('09AAJFV1437D1Z7', $invoice->buyer_gstin);
    }

    public function test_service_auto_b2c_mint_remains_unchanged(): void
    {
        $order = $this->serviceOrderWithInactiveVerification();

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame('Rajasthan', $invoice->place_of_supply_state);
    }

    public function test_product_confirmed_ineligible_mints_b2c_without_stage_b_fallback(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false]);
        $order = $this->productOrderWithInactiveVerification(autoConvert: false);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame(1, CommerceOrderBuyerGstB2cConversion::query()->where('commerce_order_id', $order->id)->count());
        $conversion = CommerceOrderBuyerGstB2cConversion::query()->firstOrFail();
        $this->assertNull($conversion->authorized_by);
        $this->assertSame($invoice->id, $conversion->statutory_invoice_id);
    }

    public function test_duplicate_product_b2c_mint_is_idempotent(): void
    {
        $order = $this->productOrderWithInactiveVerification();

        $first = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);
        $second = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CommerceOrderBuyerGstB2cConversion::query()->where('commerce_order_id', $order->id)->count());
    }

    public function test_product_b2c_preserves_taxable_value_and_place_of_supply(): void
    {
        $order = $this->productOrderWithInactiveVerification();

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame(100.0, (float) $invoice->taxable_value);
        $this->assertSame(18.0, (float) $invoice->tax_total);
        $this->assertSame('Rajasthan', $invoice->place_of_supply_state);
        $this->assertSame(18.0, (float) $invoice->items->first()->gst_percentage);
    }

    public function test_post_mint_conversion_remains_blocked(): void
    {
        $order = $this->productOrderWithInactiveVerification(autoConvert: false);
        $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->expectException(ValidationException::class);
        app(CommerceOrderBuyerGstB2cConversionService::class)->approve(
            $order->fresh(),
            $this->actor,
            'Too late',
        );
    }

    private function productOrderWithInactiveVerification(bool $autoConvert = true): CommerceOrder
    {
        $order = $this->productOrder();
        CommerceOrderBuyerGstVerification::query()->create([
            'commerce_order_id' => $order->id,
            'submitted_gstin' => '07AAICB5215F2ZX',
            'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
            'registration_status' => 'Suspended',
            'review_reason' => 'inactive_registration',
            'provider_payload_hash' => hash('sha256', 'product-b2c-fixture'),
        ]);

        if ($autoConvert) {
            $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order->fresh());
            $this->assertNotNull($conversion);
            $this->assertSame(BuyerGstB2cConversionStatus::Approved, $conversion->status);
        }

        return $order->fresh(['items']);
    }

    private function serviceOrderWithInactiveVerification(): CommerceOrder
    {
        $order = $this->serviceOrder();
        CommerceOrderBuyerGstVerification::query()->create([
            'commerce_order_id' => $order->id,
            'submitted_gstin' => '07AAICB5215F2ZX',
            'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
            'registration_status' => 'Suspended',
            'review_reason' => 'inactive_registration',
            'provider_payload_hash' => hash('sha256', 'service-b2c-fixture'),
        ]);
        app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order->fresh());

        return $order->fresh(['items']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function productOrder(array $overrides = []): CommerceOrder
    {
        $suffix = bin2hex(random_bytes(4));
        $order = CommerceOrder::query()->create(array_merge([
            'order_no' => 'CO-PROD-B2C-'.$suffix,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'UAT-PROD-B2C-'.$suffix,
            'source_order_id' => 'UAT-PROD-B2C-'.$suffix,
            'idempotency_key' => 'test:prod-b2c:'.$suffix,
            'payload_hash' => hash('sha256', 'prod-b2c:'.$suffix),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'buyer_gstin' => '07AAICB5215F2ZX',
            'billing_address_structured' => ['state' => 'Rajasthan', 'city' => 'Jodhpur', 'pincode' => '342001'],
            'shipping_address_structured' => ['state' => 'Rajasthan', 'city' => 'Jodhpur', 'pincode' => '342001'],
            'place_of_supply_state' => 'Rajasthan',
            'billing_state' => 'Rajasthan',
            'branch_code' => 'DELHI-RETAIL',
            'order_value' => 118,
            'taxable_value' => 100,
            'tax_total' => 18,
            'ordered_at' => now(),
            'received_at' => now(),
        ], $overrides));

        $order->items()->create([
            'line_no' => 1,
            'sku' => 'MFS110-UAT',
            'description' => 'Mantra MFS110 product line',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'igst' => 18,
            'line_total' => 118,
        ]);

        return $order->fresh(['items']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function serviceOrder(array $overrides = []): CommerceOrder
    {
        $suffix = bin2hex(random_bytes(4));
        $order = CommerceOrder::query()->create(array_merge([
            'order_no' => 'CO-SVC-B2C-'.$suffix,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'UAT-SVC-B2C-'.$suffix,
            'source_order_id' => 'UAT-SVC-B2C-'.$suffix,
            'idempotency_key' => 'test:svc-b2c:'.$suffix,
            'payload_hash' => hash('sha256', 'svc-b2c:'.$suffix),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'buyer_gstin' => '07AAICB5215F2ZX',
            'billing_address_structured' => ['state' => 'Rajasthan', 'city' => 'Jodhpur', 'pincode' => '342001'],
            'place_of_supply_state' => 'Rajasthan',
            'billing_state' => 'Rajasthan',
            'order_value' => 118,
            'taxable_value' => 100,
            'tax_total' => 18,
            'ordered_at' => now(),
            'received_at' => now(),
        ], $overrides));

        $order->items()->create([
            'line_no' => 1,
            'sku' => 'RD-SVC',
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'igst' => 18,
            'line_total' => 118,
        ]);

        return $order->fresh(['items']);
    }
}
