<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StatutoryInvoiceOptionalLineSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-07 18:28:19');
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

    public function test_unselected_duration_support_line_is_omitted_from_invoice_and_pdf(): void
    {
        $order = $this->commerceOrder('RD-OPT-OUT', [
            $this->serviceLine(
                'Information technology (IT) consulting & support services (SAC - 998313) - (Sr. No. 9732841) - 1 Year Unlimited',
                422.88,
                76.12,
                499.0,
            ),
            $this->serviceLine('RD Technical Support — included', 0, 0, 0, variant: 'regular'),
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $pdf = $this->pdfText($invoice->id);

        $this->assertCount(1, $invoice->items);
        $this->assertStringContainsString('1 Year Unlimited', (string) $invoice->items[0]->description);
        $this->assertSame(499.0, (float) $invoice->invoice_value);
        $this->assertStringNotContainsString('RD Technical Support - included', $pdf);
        $this->assertStringNotContainsString('Not Required', $pdf);
    }

    public function test_purchased_express_duration_support_remains_with_correct_totals(): void
    {
        $order = $this->commerceOrder('RD-OPT-IN', [
            $this->serviceLine(
                'Information technology (IT) consulting & support services (SAC - 998313) - 1 Year Unlimited',
                422.88,
                76.12,
                499.0,
            ),
            $this->serviceLine('RD Technical Support — priority assistance', 50.0, 9.0, 59.0, variant: 'express'),
        ], taxable: 472.88, tax: 85.12, total: 558.0);

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $pdf = $this->pdfText($invoice->id);

        $this->assertCount(2, $invoice->items);
        $this->assertSame(558.0, (float) $invoice->invoice_value);
        $this->assertStringContainsString('priority assistance', $pdf);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function commerceOrder(
        string $sourceId,
        array $lines,
        float $taxable = 422.88,
        float $tax = 76.12,
        float $total = 499.0,
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
            'customer_name' => 'Service Buyer',
            'billing_state' => 'Maharashtra',
            'place_of_supply_state' => 'Maharashtra',
            'branch_code' => 'MUMBAI',
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'order_value' => $total,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            $order->items()->create([
                'line_no' => $index + 1,
                ...$line,
            ]);
        }

        return $order->fresh(['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceLine(
        string $description,
        float $taxable,
        float $tax,
        float $total,
        ?string $variant = null,
    ): array {
        return [
            'description' => $description,
            'variant' => $variant,
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => $taxable,
            'gst_percentage' => 18,
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => $total,
        ];
    }

    private function pdfText(int $invoiceId): string
    {
        $invoice = StatutoryInvoice::query()->with('document')->findOrFail($invoiceId);
        $binary = app(StatutoryDocumentService::class)->binary($invoice->document);

        if (preg_match_all('/\(([^()]*)\)/', $binary, $matches)) {
            return implode('', $matches[1]);
        }

        return $binary;
    }
}
