<?php

namespace Tests\Feature\ServicePos;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\ServiceItem;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\ServicePos\ServiceQuoteService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Production-verified DeskService GST split (v4.0.82 / P-18-09-23).
 *
 * Locks intra-state CGST+SGST and inter-state IGST on issueFromServiceOrder().
 */
class ServicePosGstSplitTest extends TestCase
{
    use RefreshDatabase;

    private ServiceQuoteService $quotes;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    private InventoryBranch $branch;

    private InventoryCustomer $customer;

    private ServiceItem $rdItem;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 14:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        Storage::fake('local');

        $this->quotes = app(ServiceQuoteService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $this->customer = InventoryCustomer::query()->create([
            'name' => 'GST Split Customer',
            'phone' => '9999900002',
            'email' => 'gst-split@example.test',
        ]);

        $this->rdItem = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_intra_state_desk_service_invoice_persists_cgst_sgst(): void
    {
        $invoice = $this->issueSingleLineInvoice('Delhi', 'Delhi');

        $this->assertSame(StatutoryInvoiceChannel::DeskService, $invoice->channel);
        $this->assertSame(StatutoryInvoiceSourceType::ServiceOrder->value, $invoice->source_type);
        $this->assertSame('Delhi', $invoice->place_of_supply_state);
        $this->assertSame(38.06, (float) $invoice->cgst);
        $this->assertSame(38.06, (float) $invoice->sgst);
        $this->assertSame(0.0, (float) $invoice->igst);

        $line = $invoice->items->first();
        $this->assertSame('998313', $line->hsn_sac);
        $this->assertSame(422.88, (float) $line->taxable_value);
        $this->assertSame(18.0, (float) $line->gst_percentage);
        $this->assertSame(76.12, (float) $line->tax_total);
        $this->assertSame(38.06, (float) $line->cgst);
        $this->assertSame(38.06, (float) $line->sgst);
        $this->assertSame(0.0, (float) $line->igst);
        $this->assertSame(499.0, (float) $line->line_total);
        $this->assertSame(499.0, (float) $invoice->invoice_value);
    }

    public function test_inter_state_desk_service_invoice_persists_igst(): void
    {
        $invoice = $this->issueSingleLineInvoice('Karnataka', 'Karnataka');

        $this->assertSame('Karnataka', $invoice->place_of_supply_state);
        $this->assertSame(0.0, (float) $invoice->cgst);
        $this->assertSame(0.0, (float) $invoice->sgst);
        $this->assertSame(76.12, (float) $invoice->igst);

        $line = $invoice->items->first();
        $this->assertSame('998313', $line->hsn_sac);
        $this->assertSame(76.12, (float) $line->igst);
        $this->assertSame(0.0, (float) $line->cgst);
        $this->assertSame(0.0, (float) $line->sgst);
        $this->assertSame(499.0, (float) $invoice->invoice_value);
    }

    public function test_desk_service_invoice_pdf_generation_succeeds(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required for DeskService PDF generation regression.');
        }

        Storage::fake('local');

        $invoice = $this->issueSingleLineInvoice('Delhi', 'Delhi');

        app(StatutoryDocumentService::class)->generate($invoice->fresh(['items']));

        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($document);
        $this->assertSame(StatutoryInvoiceDocumentStatus::Generated, $document->status);
        $this->assertTrue(Storage::disk('local')->exists('statutory-invoices/'.$invoice->id.'.pdf'));
    }

    private function issueSingleLineInvoice(string $billingState, string $placeOfSupplyState)
    {
        $quote = $this->quotes->createQuote(
            $this->customer,
            $this->branch,
            [['service_item_id' => $this->rdItem->id, 'qty' => 1]],
            $this->actor,
            billingState: $billingState,
            placeOfSupplyState: $placeOfSupplyState,
        );

        $order = $this->quotes->convertToOrder($quote, $this->actor);

        return $this->invoices->issueFromServiceOrder($order->fresh(['lines']), $this->actor)
            ->fresh(['items']);
    }
}
