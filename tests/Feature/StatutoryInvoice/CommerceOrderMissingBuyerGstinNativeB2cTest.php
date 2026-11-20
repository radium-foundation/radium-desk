<?php

namespace Tests\Feature\StatutoryInvoice;

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
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P-20-14 — Case #4: native B2C when buyer GSTIN is absent (not conversion-driven).
 */
#[Group('permanent_regression')]
class CommerceOrderMissingBuyerGstinNativeB2cTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 09:00:00');

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

        Http::fake();

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_product_missing_buyer_gstin_mints_native_b2c_without_conversion_row(): void
    {
        $order = $this->productOrderMissingGstin();

        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order->fresh());
        $this->assertNull($conversion);
        $this->assertSame(0, CommerceOrderBuyerGstB2cConversion::query()->where('commerce_order_id', $order->id)->count());
        $this->assertSame(0, CommerceOrderBuyerGstVerification::query()->where('commerce_order_id', $order->id)->count());

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertNull($invoice->buyer_gstin);
        $this->assertNull($order->fresh()->buyer_gstin);
        Http::assertNothingSent();
    }

    public function test_service_missing_buyer_gstin_mints_native_b2c_without_conversion_row(): void
    {
        $order = $this->serviceOrderMissingGstin();

        $this->assertNull(
            app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order->fresh())
        );

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame('Rajasthan', $invoice->place_of_supply_state);
        $this->assertSame(0, CommerceOrderBuyerGstB2cConversion::query()->where('commerce_order_id', $order->id)->count());
    }

    public function test_missing_gstin_preserves_taxable_value_tax_total_and_place_of_supply(): void
    {
        $order = $this->productOrderMissingGstin([
            'place_of_supply_state' => 'Rajasthan',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame('Rajasthan', $invoice->place_of_supply_state);
        $this->assertSame('100.00', number_format((float) $invoice->taxable_value, 2, '.', ''));
        $this->assertSame('18.00', number_format((float) $invoice->tax_total, 2, '.', ''));
    }

    public function test_missing_gstin_invoice_is_not_irn_eligible_and_queues_skipped(): void
    {
        $order = $this->serviceOrderMissingGstin();

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $result = app(EInvoiceEligibility::class)->evaluate($invoice->fresh());
        $this->assertFalse($result->eligible);
        $this->assertSame('b2c_not_eligible', $result->reason);

        $this->invoices->queueEinvoiceIfEligible($invoice->fresh());
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
    }

    public function test_missing_gstin_duplicate_mint_is_idempotent(): void
    {
        $order = $this->productOrderMissingGstin();

        $first = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);
        $second = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $first->fresh()->allocation()->count());
    }

    public function test_missing_gstin_with_fallback_disabled_remains_native_b2c(): void
    {
        config([
            'statutory_invoices.buyer_gstin_verification.enabled' => true,
            'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false,
        ]);

        $order = $this->productOrderMissingGstin();

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame(0, CommerceOrderBuyerGstB2cConversion::query()->where('commerce_order_id', $order->id)->count());
    }

    public function test_present_valid_gstin_b2b_unchanged_with_fallback_enabled(): void
    {
        $order = $this->productOrderMissingGstin([
            'buyer_gstin' => '09AAJFV1437D1Z7',
            'billing_state' => 'Uttar Pradesh',
            'place_of_supply_state' => 'Uttar Pradesh',
            'billing_address_structured' => ['state' => 'Uttar Pradesh', 'city' => 'Lucknow', 'pincode' => '226001'],
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame('09AAJFV1437D1Z7', $invoice->buyer_gstin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function productOrderMissingGstin(array $overrides = []): CommerceOrder
    {
        $suffix = bin2hex(random_bytes(4));

        return $this->createCommerceOrder(array_merge([
            'order_no' => 'CO-NO-GSTIN-PROD-'.$suffix,
            'source_id' => 'UAT-NO-GSTIN-PROD-'.$suffix,
            'buyer_gstin' => null,
            'sku' => 'MFS110-UAT',
            'hsn_sac' => '84716050',
            'description' => 'Mantra MFS110 product line',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function serviceOrderMissingGstin(array $overrides = []): CommerceOrder
    {
        $suffix = bin2hex(random_bytes(4));

        return $this->createCommerceOrder(array_merge([
            'order_no' => 'CO-NO-GSTIN-SVC-'.$suffix,
            'source_id' => 'UAT-NO-GSTIN-SVC-'.$suffix,
            'buyer_gstin' => null,
            'sku' => 'RD-SVC',
            'hsn_sac' => '998314',
            'description' => 'Information technology (IT) consulting & support services',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCommerceOrder(array $attributes): CommerceOrder
    {
        $sourceId = (string) $attributes['source_id'];
        $sku = (string) ($attributes['sku'] ?? 'SKU');
        $hsnSac = (string) ($attributes['hsn_sac'] ?? '84716050');
        $description = (string) ($attributes['description'] ?? 'Line');
        unset($attributes['source_id'], $attributes['sku'], $attributes['hsn_sac'], $attributes['description']);

        $order = CommerceOrder::query()->create(array_merge([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_order_id' => $sourceId,
            'idempotency_key' => 'test:no-gstin:'.$sourceId,
            'payload_hash' => hash('sha256', 'no-gstin:'.$sourceId),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
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
        ], $attributes, [
            'source_id' => $sourceId,
        ]));

        $order->items()->create([
            'line_no' => 1,
            'sku' => $sku,
            'description' => $description,
            'hsn_sac' => $hsnSac,
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
