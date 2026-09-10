<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Events\Finance\OrderPaid;
use App\Models\CommerceOrder;
use App\Models\InvoiceSequenceAllocation;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\GstSplitService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceGstSplitIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 18:00:00');

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

    public function test_maharashtra_seller_and_pos_18_percent_persists_cgst_sgst(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-GST-MH-INTRA', billingState: 'Maharashtra', placeOfSupply: 'Maharashtra'),
            $this->actor,
        );

        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('mumbai'), $invoice->seller_gstin);
        $this->assertGstSplit($invoice, 38.06, 38.06, 0.0);
    }

    public function test_delhi_seller_and_pos_18_percent_persists_cgst_sgst(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-GST-DL-INTRA', billingState: 'Delhi', placeOfSupply: 'Delhi'),
            $this->actor,
        );

        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertGstSplit($invoice, 38.06, 38.06, 0.0);
    }

    public function test_maharashtra_seller_and_non_maharashtra_pos_persists_igst(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-GST-MH-INTER', billingState: 'Maharashtra', placeOfSupply: 'Karnataka'),
            $this->actor,
        );

        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertGstSplit($invoice, 0.0, 0.0, 76.12);
    }

    public function test_delhi_seller_and_maharashtra_pos_persists_igst(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-GST-DL-INTER', billingState: 'Delhi', placeOfSupply: 'Maharashtra'),
            $this->actor,
        );

        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertGstSplit($invoice, 0.0, 0.0, 76.12);
    }

    public function test_intra_state_rounding_reconciles_to_authoritative_tax_total(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-GST-ROUND', billingState: 'Maharashtra', placeOfSupply: 'Maharashtra'),
            $this->actor,
        );

        $this->assertSame(76.12, (float) $invoice->tax_total);
        $this->assertSame(76.12, round((float) $invoice->cgst + (float) $invoice->sgst + (float) $invoice->igst, 2));
        $this->assertSame(499.0, (float) $invoice->invoice_value);
    }

    public function test_missing_place_of_supply_fails_closed_without_allocation(): void
    {
        $order = $this->commerceOrder('RD-GST-NO-POS', billingState: 'Maharashtra', placeOfSupply: null);

        $this->assertContains(
            GstSplitService::PLACE_OF_SUPPLY_MISSING,
            app(StatutoryMintEligibility::class)->evaluateOrder($order)->errors,
        );

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected missing place of supply to fail closed.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_invalid_gst_rate_fails_closed_without_allocation(): void
    {
        $order = $this->commerceOrder(
            'RD-GST-BAD-RATE',
            billingState: 'Maharashtra',
            placeOfSupply: 'Maharashtra',
            gstPercentage: 0.0,
        );

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected invalid GST rate to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                GstSplitService::GST_RATE_INVALID,
                implode(' ', $this->flattenErrors($exception)),
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_missing_gst_rate_fails_closed_without_allocation(): void
    {
        $order = $this->commerceOrder(
            'RD-GST-NO-RATE',
            billingState: 'Maharashtra',
            placeOfSupply: 'Maharashtra',
            gstPercentage: null,
        );

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected missing GST rate to fail closed.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_tax_mismatch_fails_closed_without_allocation(): void
    {
        $order = $this->commerceOrder(
            'RD-GST-MISMATCH',
            billingState: 'Maharashtra',
            placeOfSupply: 'Maharashtra',
            taxTotal: 10.00,
            orderValue: 432.88,
        );

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected tax mismatch to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                GstSplitService::TAX_MISMATCH,
                implode(' ', $this->flattenErrors($exception)),
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_b2b_split_follows_seller_state_and_place_of_supply_not_billing_state(): void
    {
        $intra = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder(
                'RD-GST-B2B-INTRA',
                billingState: 'Delhi',
                buyerGstin: '27AAAAA0000A1Z5',
                placeOfSupply: 'Maharashtra',
            ),
            $this->actor,
        );
        $inter = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder(
                'RD-GST-B2B-INTER',
                billingState: 'Maharashtra',
                buyerGstin: '07AAAAA0000A1Z5',
                placeOfSupply: 'Maharashtra',
            ),
            $this->actor,
        );

        $this->assertSame($this->configuredSellerGstin('mumbai'), $intra->seller_gstin);
        $this->assertSame('27AAAAA0000A1Z5', $intra->buyer_gstin);
        $this->assertGstSplit($intra, 38.06, 38.06, 0.0);

        $this->assertSame($this->configuredSellerGstin('delhi'), $inter->seller_gstin);
        $this->assertSame('07AAAAA0000A1Z5', $inter->buyer_gstin);
        $this->assertGstSplit($inter, 0.0, 0.0, 76.12);
    }

    public function test_retry_keeps_identity_and_does_not_duplicate_tax_components(): void
    {
        $order = $this->commerceOrder('RD-GST-RETRY', billingState: 'Maharashtra', placeOfSupply: 'Maharashtra');

        $first = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $second = $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('INV-27671', $second->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
        $this->assertSame(2, StatutoryInvoiceItem::query()->where('invoice_id', $first->id)->count());
        $this->assertGstSplit($second, 38.06, 38.06, 0.0);
    }

    public function test_payment_success_still_does_not_issue(): void
    {
        $desk = Order::query()->create([
            'order_id' => 'RD-GST-PAID',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->commerceOrder('RD-GST-PAID', billingState: 'Maharashtra', placeOfSupply: 'Maharashtra');

        OrderPaid::dispatch($desk);

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_existing_issued_invoice_components_and_pdf_are_not_rewritten(): void
    {
        $order = $this->commerceOrder('RD-GST-HIST', billingState: 'Maharashtra', placeOfSupply: 'Maharashtra');
        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $checksum = $document->checksum;
        $binary = app(StatutoryDocumentService::class)->binary($document);

        $replay = $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);
        $this->assertSame($invoice->id, $replay->id);
        $this->assertGstSplit($replay, 38.06, 38.06, 0.0);
        $this->assertSame($checksum, $replay->document?->checksum ?? $document->fresh()->checksum);
        $this->assertSame($binary, app(StatutoryDocumentService::class)->binary($document->fresh()));

        DB::table('statutory_invoices')->where('id', $invoice->id)->update([
            'cgst' => null,
            'sgst' => null,
            'igst' => null,
        ]);
        DB::table('statutory_invoice_items')->where('invoice_id', $invoice->id)->update([
            'cgst' => null,
            'sgst' => null,
            'igst' => null,
        ]);

        $historical = $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);
        $historicalDocument = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame($invoice->id, $historical->id);
        $this->assertNull($historical->cgst);
        $this->assertNull($historical->sgst);
        $this->assertNull($historical->igst);
        $this->assertSame($checksum, $historicalDocument->checksum);
        $this->assertSame($binary, app(StatutoryDocumentService::class)->binary($historicalDocument));
        $this->assertSame(StatutoryInvoiceDocumentStatus::Generated, $historicalDocument->status);
    }

    public function test_pdf_and_html_show_rate_components_and_full_chargeable_line(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-GST-DOC', billingState: 'Maharashtra', placeOfSupply: 'Maharashtra'),
            $this->actor,
        );
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $pdf = app(StatutoryDocumentService::class)->binary($document);

        $this->assertStringContainsString('consulting', $pdf);
        $this->assertStringContainsString('support', $pdf);
        $this->assertStringContainsString('services', $pdf);
        $this->assertStringContainsString('HSN/SAC', $pdf);
        $this->assertStringContainsString('998313', $pdf);
        $this->assertStringContainsString('18.00%', $pdf);
        $this->assertStringContainsString('CGST', $pdf);
        $this->assertStringContainsString('SGST', $pdf);
        $this->assertStringContainsString('Rs.38.06', $pdf);
        $this->assertStringNotContainsString('IGST Rs.0.00', $pdf);
        $this->assertStringContainsString('Rs.422.88', $pdf);
        $this->assertStringNotContainsString('not recorded', $pdf);
        $this->assertStringNotContainsString('unset', $pdf);

        $this->withoutVite();
        $this->actingAs($this->actor)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('998313', false)
            ->assertSee('18.00', false)
            ->assertSee('38.06', false)
            ->assertSee('0.00', false)
            ->assertSee('Information technology (IT) consulting', false)
            ->assertDontSee('not recorded', false);
    }

    public function test_pdf_does_not_render_null_tax_fields_as_zero(): void
    {
        $invoice = $this->invoices->mint(new StatutoryInvoiceMintRequest(
            channel: StatutoryInvoiceChannel::DeskPos,
            sourceType: StatutoryInvoiceSourceType::External,
            sourceId: 'null-tax-pdf',
            lines: [
                new StatutoryInvoiceLineDraft(
                    description: 'Historical line',
                    qty: 1,
                    unitPrice: 100,
                    gstPercentage: 18,
                    taxTotal: 18,
                    lineTotal: 118,
                    taxableValue: 100,
                    hsnSac: '84716050',
                ),
            ],
            numberingLocation: StatutoryLocationSeries::DELHI,
            financialYearToken: '2026-2027',
        ), $this->actor);

        $this->assertNull($invoice->cgst);
        $this->assertNull($invoice->sgst);
        $this->assertNull($invoice->igst);

        $document = app(StatutoryDocumentService::class)->generate($invoice);
        $pdf = app(StatutoryDocumentService::class)->binary($document);

        $this->assertStringContainsString('CGST', $pdf);
        $this->assertStringContainsString('SGST', $pdf);
        $this->assertStringContainsString('IGST', $pdf);
        $this->assertStringNotContainsString('not recorded', $pdf);
        $this->assertStringNotContainsString('CGST Rs.0', $pdf);
        $this->assertStringNotContainsString('SGST Rs.0', $pdf);
        $this->assertStringNotContainsString('IGST Rs.0', $pdf);
    }

    private function assertGstSplit(StatutoryInvoice $invoice, float $cgst, float $sgst, float $igst): void
    {
        $invoice->loadMissing('items');

        $this->assertSame($cgst, (float) $invoice->cgst);
        $this->assertSame($sgst, (float) $invoice->sgst);
        $this->assertSame($igst, (float) $invoice->igst);
        $this->assertSame(76.12, (float) $invoice->tax_total);

        foreach ($invoice->items as $line) {
            $this->assertNotNull($line->cgst);
            $this->assertNotNull($line->sgst);
            $this->assertNotNull($line->igst);
        }

        $chargeable = $invoice->items->firstWhere('taxable_value', 422.88) ?? $invoice->items->first();
        $this->assertSame($cgst, (float) $chargeable->cgst);
        $this->assertSame($sgst, (float) $chargeable->sgst);
        $this->assertSame($igst, (float) $chargeable->igst);
    }

    private function commerceOrder(
        string $sourceId,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
        ?string $placeOfSupply = 'Delhi',
        ?float $gstPercentage = 18.0,
        float $taxableValue = 422.88,
        float $taxTotal = 76.12,
        float $orderValue = 499.0,
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
            'buyer_gstin' => $buyerGstin,
            'billing_state' => $billingState,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => $placeOfSupply,
            'taxable_value' => $taxableValue,
            'tax_total' => $taxTotal,
            'order_value' => $orderValue,
            'ordered_at' => '2026-09-01 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => $taxableValue,
            'gst_percentage' => $gstPercentage,
            'taxable_value' => $taxableValue,
            'tax_total' => $taxTotal,
            'line_total' => $orderValue,
        ]);
        $order->items()->create([
            'line_no' => 2,
            'description' => 'Zero companion',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => $gstPercentage ?? 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        return $order->fresh(['items']);
    }

    /**
     * @return list<string>
     */
    private function flattenErrors(ValidationException $exception): array
    {
        $flat = [];
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $flat[] = (string) $message;
            }
        }

        return $flat;
    }
}
