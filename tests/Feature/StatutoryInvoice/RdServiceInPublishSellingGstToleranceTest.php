<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\PublishSellingExclusiveGstTolerance;
use App\Services\StatutoryInvoice\StatutoryInvoiceScope;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RdServiceInPublishSellingGstToleranceTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private StatutoryMintEligibility $eligibility;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

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
            'channel_ingest.auto_issue_invoice' => false,
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->eligibility = app(StatutoryMintEligibility::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_rd5777_shape_one_paisa_publish_selling_difference_passes_mint_eligibility(): void
    {
        $order = $this->commerceOrder('RD5777', taxTotal: 134.08, orderValue: 879.0);

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_production_tier_631_tax_low_one_paisa_passes_mint_eligibility(): void
    {
        $order = $this->commerceOrder(
            'RD3512512',
            taxableValue: 534.75,
            taxTotal: 96.25,
            orderValue: 631.0,
        );

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_production_tier_789_98_tax_high_one_paisa_passes_mint_eligibility(): void
    {
        $order = $this->commerceOrder(
            'RD3512494',
            taxableValue: 669.47,
            taxTotal: 120.51,
            orderValue: 789.98,
        );

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_production_tier_879_tax_low_issues_with_source_tax_preserved(): void
    {
        $order = $this->commerceOrder('RD3512449', taxTotal: 134.08, orderValue: 879.0);

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame(744.92, (float) $invoice->items[0]->taxable_value);
        $this->assertSame(134.08, (float) $invoice->items[0]->tax_total);
        $this->assertSame(879.0, (float) $invoice->items[0]->line_total);
    }

    public function test_exact_exclusive_tax_match_passes_mint_eligibility(): void
    {
        $order = $this->commerceOrder('RD5776', taxTotal: 134.09, orderValue: 879.01);

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_more_than_one_paisa_tax_mismatch_fails_mint_eligibility(): void
    {
        $order = $this->commerceOrder('RD5775', taxTotal: 134.07, orderValue: 878.99);

        $result = $this->eligibility->evaluateOrder($order);

        $this->assertFalse($result->eligible);
        $this->assertContains('GST amount does not match taxable value × rate.', $result->errors);
    }

    public function test_rd5777_shape_issues_with_source_tax_preserved(): void
    {
        $order = $this->commerceOrder('RD5777', taxTotal: 134.08, orderValue: 879.0);

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame(744.92, (float) $invoice->items[0]->taxable_value);
        $this->assertSame(134.08, (float) $invoice->items[0]->tax_total);
        $this->assertSame(879.0, (float) $invoice->items[0]->line_total);
        $this->assertSame(134.08, (float) $invoice->igst);
        $this->assertSame(879.0, (float) $invoice->invoice_value);
    }

    public function test_more_than_one_paisa_tax_mismatch_fails_issuance(): void
    {
        $order = $this->commerceOrder('RD5775', taxTotal: 134.07, orderValue: 878.99);

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected rdservice.in RD* tax mismatch beyond one paisa to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'GST amount does not match taxable value × rate.',
                implode(' ', array_merge(...array_values($exception->errors()))),
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_hardware_rdp_order_does_not_receive_publish_selling_tolerance(): void
    {
        $this->assertFalse(PublishSellingExclusiveGstTolerance::allowsOnePaisa(
            StatutoryInvoiceChannel::RdServiceIn,
            'RDP900101',
        ));

        $order = $this->commerceOrder('RDP900101', taxTotal: 134.07, orderValue: 878.99);

        $result = $this->eligibility->evaluateOrder($order);

        $this->assertFalse($result->eligible);
        $this->assertContains('GST amount does not match taxable value × rate.', $result->errors);
    }

    public function test_radiumbox_commerce_order_does_not_receive_rdservice_in_tolerance(): void
    {
        $this->assertFalse(PublishSellingExclusiveGstTolerance::allowsOnePaisa(
            StatutoryInvoiceChannel::RadiumBoxCom,
            'RD5777',
            Carbon::parse('2026-09-18 18:48:07'),
        ));
    }

    public function test_one_paisa_tolerance_does_not_apply_before_september_scope(): void
    {
        $commercialAt = Carbon::parse('2026-08-31 23:59:59');

        $this->assertFalse(StatutoryInvoiceScope::contains($commercialAt));
        $this->assertFalse(PublishSellingExclusiveGstTolerance::allowsOnePaisa(
            StatutoryInvoiceChannel::RdServiceIn,
            'RD5777',
            $commercialAt,
        ));

        $order = $this->commerceOrder('RD5777', taxTotal: 134.08, orderValue: 879.0, orderedAt: $commercialAt);
        $result = $this->eligibility->evaluateOrder($order);

        $this->assertFalse($result->eligible);
        $this->assertContains('Order is outside the 2026-09-01 invoice scope.', $result->errors);
    }

    public function test_one_paisa_tolerance_applies_on_september_first(): void
    {
        $commercialAt = Carbon::parse(StatutoryInvoiceScope::STARTS_AT);

        $this->assertTrue(StatutoryInvoiceScope::contains($commercialAt));
        $this->assertTrue(PublishSellingExclusiveGstTolerance::allowsOnePaisa(
            StatutoryInvoiceChannel::RdServiceIn,
            'RD5777',
            $commercialAt,
        ));

        $order = $this->commerceOrder('RD5777', taxTotal: 134.08, orderValue: 879.0, orderedAt: $commercialAt);

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_one_paisa_tolerance_applies_after_september_first(): void
    {
        $commercialAt = Carbon::parse('2026-09-02 10:00:00');

        $this->assertTrue(PublishSellingExclusiveGstTolerance::allowsOnePaisa(
            StatutoryInvoiceChannel::RdServiceIn,
            'RD5777',
            $commercialAt,
        ));

        $order = $this->commerceOrder('RD5777', taxTotal: 134.08, orderValue: 879.0, orderedAt: $commercialAt);

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_rdservice_net_tolerance_is_unchanged_without_commercial_date(): void
    {
        $this->assertTrue(PublishSellingExclusiveGstTolerance::allowsOnePaisa(
            StatutoryInvoiceChannel::RdServiceNet,
            'RN1001',
        ));
    }

    private function commerceOrder(
        string $sourceId,
        float $taxableValue = 744.92,
        float $taxTotal = 134.08,
        float $orderValue = 879.0,
        ?Carbon $orderedAt = null,
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => 'Uttar Pradesh',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Uttar Pradesh',
            'taxable_value' => $taxableValue,
            'tax_total' => $taxTotal,
            'order_value' => $orderValue,
            'ordered_at' => ($orderedAt ?? Carbon::parse('2026-09-18 18:48:07'))->toDateTimeString(),
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => $taxableValue,
            'gst_percentage' => 18,
            'taxable_value' => $taxableValue,
            'tax_total' => $taxTotal,
            'line_total' => $orderValue,
        ]);

        return $order->fresh(['items']);
    }
}
