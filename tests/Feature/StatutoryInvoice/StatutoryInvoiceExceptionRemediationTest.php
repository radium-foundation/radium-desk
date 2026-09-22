<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\OutboxEvent;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ServicePos\ServiceQuoteService;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrpSubmissionHold;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\StatutoryInvoiceExceptionRemediation;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class StatutoryInvoiceExceptionRemediationTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER_GSTIN = '33AAECA0303M1Z6';

    private StatutoryInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-22 12:00:00');
        Storage::fake('local');
        Http::fake();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'fake',
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);
        $category = ServiceCategory::query()->create([
            'code' => 'freight',
            'name' => 'Freight',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $freightItem = ServiceItem::query()->create([
            'category_id' => $category->id,
            'code' => 'RBSMARKETS',
            'name' => 'Secondary Freight Reverse Auction',
            'sac_code' => '998311',
            'gst_rate' => 18,
            'price_ex_gst' => 50000,
            'is_active' => true,
        ]);
        $customer = InventoryCustomer::query()->create([
            'name' => 'Sundrop Brands Limited',
            'phone' => '9704890618',
            'gstin' => self::BUYER_GSTIN,
        ]);

        $quotes = app(ServiceQuoteService::class);
        $quote = $quotes->createQuote(
            customer: $customer,
            branch: $branch,
            lines: [['service_item_id' => $freightItem->id, 'qty' => 1]],
            actor: $admin,
            billingAddress: "SF NO. 384/9A CODEA PARK ROAD\r\nSARKARSAMA KULAM POST\r\nCOIMBATORE - 641107",
            billingState: 'Tamil Nadu',
            placeOfSupplyState: 'Tamil Nadu',
            buyerGstin: self::BUYER_GSTIN,
        );
        $order = $quotes->convertToOrder($quote, $admin);
        $this->invoice = app(StatutoryInvoiceService::class)
            ->issueFromServiceOrder($order->fresh(['lines.serviceItem']), $admin)
            ->fresh(['items']);

        DB::table('statutory_invoices')
            ->where('id', $this->invoice->id)
            ->update(['billing_address_structured' => null]);

        config()->set('statutory_invoices.einvoice.exception_remediation_extra_invoice_ids', [$this->invoice->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_resolves_newline_billing_and_becomes_submittable(): void
    {
        $report = app(StatutoryInvoiceExceptionRemediation::class)->preview(
            $this->invoice->fresh(['items']),
        );

        $this->assertFalse($report['before']['is_submittable']);
        $this->assertContains('missing_buyer_pin', $report['before']['payload_gaps']);
        $this->assertTrue($report['after']['is_submittable']);
        $this->assertSame([], $report['after']['payload_gaps']);
        $this->assertSame('COIMBATORE', $report['after']['billing_address_structured']['city']);
        $this->assertSame('641107', $report['after']['billing_address_structured']['pincode']);
        $this->assertSame('Tamil Nadu', $report['after']['billing_address_structured']['state']);
    }

    public function test_apply_preserves_identity_and_irp_hold_blocks_submission(): void
    {
        config()->set('statutory_invoices.einvoice.irp_submission_held_invoice_ids', [$this->invoice->id]);
        config()->set('statutory_invoices.worker_may_mint', true);
        config()->set('statutory_invoices.einvoice.provider', 'fake');

        $identityBefore = StatutoryInvoice::query()->findOrFail($this->invoice->id);
        $report = app(StatutoryInvoiceExceptionRemediation::class)->remediate(
            $this->invoice->fresh(['items']),
            apply: true,
            actor: 'test',
        );

        $this->assertTrue($report['applied']);
        $fresh = StatutoryInvoice::query()->with('items')->findOrFail($this->invoice->id);
        $this->assertSame((string) $identityBefore->invoice_number, (string) $fresh->invoice_number);
        $this->assertSame((string) $identityBefore->taxable_value, (string) $fresh->taxable_value);
        $this->assertSame((string) $identityBefore->igst, (string) $fresh->igst);
        $this->assertSame('SVC-', substr((string) $fresh->source_id, 0, 4));
        $this->assertNotNull($fresh->billing_address_structured);

        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $fresh->id],
            ['provider' => 'fake', 'status' => EInvoiceRecordStatus::Queued->value],
        );
        $outbox = OutboxEvent::query()->create([
            'event_type' => EInvoiceOutboxWriter::EVENT_TYPE,
            'aggregate_type' => EInvoiceOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $fresh->id,
            'payload' => ['invoice_id' => $fresh->id, 'invoice_number' => $fresh->invoice_number],
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'idempotency_key' => EInvoiceOutboxWriter::idempotencyKeyForInvoice($fresh).':test',
        ]);

        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: str_repeat('a', 64),
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->forgetInstance(EInvoiceProcessor::class);

        app(EInvoiceProcessor::class)->process($outbox);
        $this->assertSame(0, $fake->submitCount);
        $this->assertTrue(EInvoiceIrpSubmissionHold::isHeld($fresh->id));

        $result = app(EInvoiceProcessor::class)->generateAfterConfirmedAbsent($fresh);
        $this->assertSame('irp_submission_held', is_array($result->payload) ? $result->payload['reason'] : null);
    }
}
