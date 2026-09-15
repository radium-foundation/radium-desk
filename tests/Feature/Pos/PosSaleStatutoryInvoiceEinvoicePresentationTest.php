<?php

namespace Tests\Feature\Pos;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class PosSaleStatutoryInvoiceEinvoicePresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private int $saleSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'mail.enabled' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'fake',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);

        $this->app->instance(EInvoiceGateway::class, FakeEInvoiceGateway::succeeding());
        $this->app->forgetInstance(StatutoryInvoiceService::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_provider_disabled_skip_is_not_described_as_queued(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-DISABLED');
        $this->setRecord($sale, EInvoiceRecordStatus::Skipped->value, [
            'skip_reason' => 'provider_disabled',
        ], provider: 'none');

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice not submitted', $html);
        $this->assertStringContainsString('e-invoice provider is disabled', $html);
        $this->assertStringContainsString('It is not queued.', $html);
        $this->assertStringNotContainsString('queued for IRN', $html);
        $this->assertStringNotContainsString('Refresh this page after the queue worker runs', $html);
    }

    public function test_worker_off_skip_is_not_described_as_queued(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-WORKER-OFF');
        $this->setRecord($sale, EInvoiceRecordStatus::Skipped->value, [
            'skip_reason' => 'worker_may_mint_off',
        ], provider: 'none');

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice not submitted', $html);
        $this->assertStringContainsString('It is not queued.', $html);
        $this->assertStringNotContainsString('queued for IRN', $html);
    }

    public function test_other_skipped_states_are_not_described_as_queued(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-SKIPPED');
        $this->setRecord($sale, EInvoiceRecordStatus::Skipped->value, [
            'skip_reason' => 'irp_fields_incomplete',
            'gaps' => ['missing_uqc'],
        ]);

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice not submitted', $html);
        $this->assertStringContainsString('was skipped and is not queued for IRN', $html);
        $this->assertStringNotContainsString('Eligible B2B invoice is queued for IRN.', $html);
        $this->assertStringNotContainsString('Refresh this page after the queue worker runs', $html);
    }

    public function test_queued_state_still_says_queued_for_irn(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-QUEUED');
        $this->assertSame(
            EInvoiceRecordStatus::Queued->value,
            $sale->statutoryInvoice?->eInvoiceRecord?->status,
        );

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice pending', $html);
        $this->assertStringContainsString('Eligible B2B invoice is queued for IRN.', $html);
        $this->assertStringContainsString('Refresh this page after the queue worker runs', $html);
        $this->assertStringNotContainsString('e-invoice provider is disabled', $html);
    }

    public function test_processing_state_still_says_queued_for_irn(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-PROCESSING');
        $this->setRecord($sale, EInvoiceRecordStatus::Processing->value, null, provider: 'whitebooks');

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice pending', $html);
        $this->assertStringContainsString('Eligible B2B invoice is queued for IRN.', $html);
        $this->assertStringNotContainsString('e-invoice provider is disabled', $html);
    }

    public function test_failed_state_keeps_not_generated_semantics(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-FAILED');
        $this->setRecord($sale, EInvoiceRecordStatus::PermanentFailure->value, [
            'error_code' => 'INVALID',
        ]);

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice not generated', $html);
        $this->assertStringContainsString('The e-invoice provider did not issue an IRN for this invoice.', $html);
        $this->assertStringContainsString('Do not generate IRN twice.', $html);
        $this->assertStringNotContainsString('queued for IRN', $html);
    }

    public function test_temporary_failure_keeps_retry_pending_semantics(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-TEMP');
        $this->setRecord($sale, EInvoiceRecordStatus::TemporaryFailure->value, [
            'reason' => 'timeout',
        ]);

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice pending', $html);
        $this->assertStringContainsString('The e-invoice provider was temporarily unavailable.', $html);
        $this->assertStringContainsString('Refresh this page shortly.', $html);
        $this->assertStringNotContainsString('queued for IRN', $html);
        $this->assertStringNotContainsString('e-invoice provider is disabled', $html);
    }

    public function test_issued_irn_is_shown_successfully(): void
    {
        $sale = $this->completeB2bSale('POS-PRES-ISSUED');
        $record = $sale->statutoryInvoice?->eInvoiceRecord;
        $this->assertNotNull($record);
        $record->forceFill([
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::Submitted->value,
            'irn' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'ack_no' => 'ACK-PRES-1',
        ])->save();

        $html = $this->saleShowHtml($sale);

        $this->assertStringContainsString('e-Invoice generated successfully', $html);
        $this->assertStringContainsString('IRN: a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', $html);
        $this->assertStringNotContainsString('queued for IRN', $html);
    }

    private function saleShowHtml(InventorySale $sale): string
    {
        return $this->actingAs($this->seller)
            ->get(route('pos.sales.show', $sale->fresh()))
            ->assertOk()
            ->getContent();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function setRecord(
        InventorySale $sale,
        string $status,
        ?array $payload,
        string $provider = 'fake',
    ): void {
        $record = EInvoiceRecord::query()
            ->where('invoice_id', $sale->statutory_invoice_id)
            ->firstOrFail();
        $record->forceFill([
            'provider' => $provider,
            'status' => $status,
            'irn' => null,
            'response_payload' => $payload,
        ])->save();
    }

    private function completeB2bSale(string $sku): InventorySale
    {
        $this->saleSeq++;
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Mantra MFS 110',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 1880,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branch, 5, $this->seller);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: [
                'name' => 'GRACE INFOLINE',
                'phone' => '90000'.str_pad((string) (1000 + $this->saleSeq), 4, '0', STR_PAD_LEFT),
            ],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
            ]],
            paymentMethod: 'Bank Transfer',
            actor: $this->seller,
            idempotencyKey: 'pos-einvoice-pres-'.$this->saleSeq,
            statutory: [
                'place_of_supply_state' => 'Delhi',
                'billing_address' => 'NEW-159-OLD-132A, MCD-128, SANT NAGAR',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110065',
                'buyer_gstin' => '07AAAAA0000A1Z5',
            ],
        );

        return $sale->fresh(['statutoryInvoice.eInvoiceRecord', 'customer', 'branch']);
    }
}
