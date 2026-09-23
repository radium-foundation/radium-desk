<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\GstSplitService;
use App\Services\StatutoryInvoice\StatutoryInvoiceCommerceBillableLines;
use App\Services\StatutoryInvoice\StatutoryInvoiceCommerceLinePresentation;
use App\Services\StatutoryInvoice\StatutoryInvoiceScope;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryMintEligibilityBillableLinesTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryMintEligibility $eligibility;

    private StatutoryInvoiceService $invoices;

    private StatutoryInvoiceCommerceLinePresentation $presentation;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 18:48:07');

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
            'statutory_invoices.invoice_scope_starts_at' => StatutoryInvoiceScope::STARTS_AT,
            'channel_ingest.auto_issue_invoice' => false,
        ]);

        $this->eligibility = app(StatutoryMintEligibility::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->presentation = app(StatutoryInvoiceCommerceLinePresentation::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_all_suppressed_zero_value_support_only_order_is_ineligible(): void
    {
        foreach (['RD3513756', 'RD2916', 'RD3162', 'RD7478'] as $sourceId) {
            $order = $this->supportOnlyOrder($sourceId);
            $result = $this->eligibility->evaluateOrder($order);

            $this->assertFalse($result->eligible, $sourceId);
            $this->assertContains(
                StatutoryInvoiceCommerceBillableLines::NO_BILLABLE_LINES,
                $result->errors,
                $sourceId,
            );
        }
    }

    public function test_mixed_billable_service_and_suppressed_support_line_is_eligible(): void
    {
        $order = $this->paidOrder('RD5778');
        $order->items()->create([
            'line_no' => 2,
            'description' => 'RD Technical Support — included',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        $this->assertTrue($this->eligibility->evaluateOrder($order->fresh(['items']))->eligible);
    }

    public function test_billable_service_only_order_is_eligible(): void
    {
        $order = $this->paidOrder('RD5779');

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_physical_merchandise_zero_value_line_remains_eligible(): void
    {
        $order = $this->paidOrder('RD5780', orderValue: 499.0, taxable: 422.88, tax: 76.12);
        $order->items()->delete();
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Hardware item',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
            'shipping_line_kind' => 'physical_merchandise',
        ]);

        $this->assertTrue(
            $this->presentation->includesOnStatutoryInvoice($order->items()->first()),
        );
        $this->assertTrue($this->eligibility->evaluateOrder($order->fresh(['items']))->eligible);
    }

    public function test_suppressed_zero_support_line_does_not_block_eligible_billable_order(): void
    {
        $order = $this->paidOrder('RD5781');
        $order->items()->create([
            'line_no' => 2,
            'description' => 'RD Technical Support — included',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        $this->assertTrue($this->eligibility->evaluateOrder($order->fresh(['items']))->eligible);
    }

    public function test_billable_line_with_missing_fields_still_blocks_eligibility(): void
    {
        $order = $this->paidOrder('RD5782');
        $order->items()->update([
            'tax_total' => null,
        ]);

        $result = $this->eligibility->evaluateOrder($order->fresh(['items']));

        $this->assertFalse($result->eligible);
        $this->assertContains(
            'A line is missing description, HSN/SAC, quantity, price, or statutory amounts.',
            $result->errors,
        );
    }

    public function test_presentation_suppression_rules_remain_unchanged(): void
    {
        $support = $this->item(description: 'RD Technical Support — included');
        $notRequired = $this->item(description: 'Not Required');
        $service = $this->item(
            description: 'Information technology (IT) consulting & support services',
            taxable: 507.63,
            tax: 91.37,
            total: 599.0,
        );
        $hardware = $this->item(description: 'Hardware item', shippingLineKind: 'physical_merchandise');

        $this->assertFalse($this->presentation->includesOnStatutoryInvoice($support));
        $this->assertFalse($this->presentation->includesOnStatutoryInvoice($notRequired));
        $this->assertTrue($this->presentation->includesOnStatutoryInvoice($service));
        $this->assertTrue($this->presentation->includesOnStatutoryInvoice($hardware));
    }

    public function test_mint_defensively_refuses_zero_billable_lines_even_if_eligibility_bypassed(): void
    {
        $order = $this->supportOnlyOrder('RD5783');

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected mint to refuse zero billable lines.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $blocked = array_merge(
                $errors['eligibility'] ?? [],
                $errors['commerce_order'] ?? [],
            );
            $this->assertContains(StatutoryInvoiceCommerceBillableLines::NO_BILLABLE_LINES, $blocked);
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_september_service_description_normalization_regression(): void
    {
        $order = $this->paidOrder('RD5784');
        $commercialAt = Carbon::parse(StatutoryInvoiceScope::STARTS_AT);
        $description = $this->presentation->invoiceDescription($order->items->first(), $commercialAt);

        $this->assertSame('IT Consulting & Support Service', $description);
    }

    public function test_one_paisa_publish_selling_tolerance_regression_on_billable_line(): void
    {
        $order = $this->paidOrder(
            'RD3512512',
            taxable: 534.75,
            tax: 96.25,
            orderValue: 631.0,
        );

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
    }

    public function test_canonical_billable_lines_helper_matches_presentation_filter(): void
    {
        $order = $this->paidOrder('RD5785');
        $order->items()->create([
            'line_no' => 2,
            'description' => 'Not Required',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);
        $order = $order->fresh(['items']);

        $expected = $order->items
            ->filter(fn ($item) => $this->presentation->includesOnStatutoryInvoice($item))
            ->pluck('description')
            ->all();

        $this->assertSame(
            $expected,
            app(StatutoryInvoiceCommerceBillableLines::class)->forOrder($order)->pluck('description')->all(),
        );
    }

    public function test_service_gst_split_validates_billable_lines_only(): void
    {
        $order = $this->paidOrder('RD5786');
        $order->items()->create([
            'line_no' => 2,
            'description' => 'RD Technical Support — included',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        $result = $this->eligibility->evaluateOrder($order->fresh(['items']));

        $this->assertTrue($result->eligible);
        $this->assertNotContains(GstSplitService::TAX_MISMATCH, $result->errors);
    }

    private function supportOnlyOrder(string $sourceId): CommerceOrder
    {
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
            'taxable_value' => 0,
            'tax_total' => 0,
            'order_value' => 0,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'RD Technical Support — included',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        return $order->fresh(['items']);
    }

    private function paidOrder(
        string $sourceId,
        float $taxable = 744.92,
        float $tax = 134.08,
        float $orderValue = 879.0,
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
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'order_value' => $orderValue,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => $taxable,
            'gst_percentage' => 18,
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => $orderValue,
        ]);

        return $order->fresh(['items']);
    }

    private function item(
        string $description,
        ?string $shippingLineKind = null,
        float $taxable = 0.0,
        float $tax = 0.0,
        float $total = 0.0,
    ): CommerceOrderItem {
        return new CommerceOrderItem([
            'description' => $description,
            'shipping_line_kind' => $shippingLineKind,
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => $taxable,
            'gst_percentage' => 18,
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => $total,
        ]);
    }
}
