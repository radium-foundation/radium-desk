<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\OutboxEventStatus;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\EInvoiceRecoveryRequiredException;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class EInvoicePosB2bAutomaticIrnLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const JWT = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoicDIzNC1hdXRob3JpdGF0aXZlLXNpZ25lZC1xciJ9.dGVzdC1zaWduYXR1cmUtcDIzNC1ub3QtcHJvZHVjdGlvbg';

    private const IRN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private User $actor;

    private InventoryBranch $branch;

    private int $phoneSeq = 2410;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-11 16:35:04');
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
            'channel_ingest.auto_issue_invoice' => false,
        ]);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_eligible_b2b_sale_dispatches_worker_persists_irn_and_finalizes_pdf(): void
    {
        $fake = $this->bindRealMapperFake();
        $sale = $this->completeSale(sku: 'RBMARC11L1-OK', serial: 'SN-IRN-OK', uqc: 'PCS', buyerGstin: '07AAAAA0000A1Z5');
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();

        $this->assertSame('PCS', $invoice->items->first()?->uqc);
        $this->assertSame(EInvoiceRecordStatus::Queued->value, $invoice->eInvoiceRecord?->status);
        $firstEvent = $this->einvoiceEvent($invoice);
        $this->assertSame(OutboxEventStatus::Pending, $firstEvent->status);

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice->fresh());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());

        $pdfBefore = $this->pdfText($invoice);
        $this->assertStringNotContainsString(self::IRN, $pdfBefore);
        $this->assertStringNotContainsString('% signed-qr-image', $this->pdfBinary($invoice));

        app(OutboxProcessorService::class)->processAggregate(
            EInvoiceOutboxWriter::AGGREGATE_TYPE,
            $invoice->id,
        );

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('ACK-2411', $record?->ack_no);
        $this->assertSame('2026-09-11 16:36:00', optional($record?->ack_date)?->format('Y-m-d H:i:s'));
        $this->assertSame(self::JWT, $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);
        $this->assertSame('corr-241', $record?->response_payload['correlation_id'] ?? null);
        $this->assertSame(OutboxEventStatus::Completed, $firstEvent->fresh()->status);

        $financial = (string) $invoice->invoice_value;
        $binary = $this->pdfBinary($invoice->fresh(['items', 'eInvoiceRecord', 'document']));
        $text = $this->pdfText($invoice);
        $this->assertStringContainsString(self::IRN, $text);
        $this->assertStringContainsString('Ack No: ACK-2411', $text);
        $this->assertStringContainsString('% signed-qr-image', $binary);
        $this->assertStringContainsString('PCS', $text);
        $this->assertStringContainsString('SN-IRN-OK', $text);
        $this->assertStringContainsString('UPI', $text);
        $this->assertStringContainsString('CIN: U72300DL2015PTC280283', $text);
        $this->assertSame($financial, (string) $invoice->fresh()->invoice_value);

        app(EInvoiceProcessor::class)->process($firstEvent->fresh());
        $this->assertSame(1, $fake->submitCount);
        Http::assertNothingSent();
    }

    public function test_missing_catalog_uqc_skips_without_provider_submit(): void
    {
        $fake = $this->bindRealMapperFake();
        $sale = $this->completeSale(sku: 'RBMARC11L1-GAP', serial: 'SN-IRN-GAP', uqc: null, buyerGstin: '07AAAAA0000A1Z5');
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();

        $this->assertNull($invoice->items->first()?->uqc);
        app(OutboxProcessorService::class)->processAggregate(
            EInvoiceOutboxWriter::AGGREGATE_TYPE,
            $invoice->id,
        );

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(0, $fake->submitCount);
        $this->assertNull($record?->irn);
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
        $this->assertSame('irp_fields_incomplete', $record?->response_payload['skip_reason'] ?? null);
        $this->assertSame(['missing_uqc'], $record?->response_payload['gaps'] ?? null);
        $this->assertStringNotContainsString(self::IRN, $this->pdfText($invoice));
        Http::assertNothingSent();
    }

    public function test_b2c_sale_does_not_queue_or_submit_irn(): void
    {
        $fake = $this->bindRealMapperFake();
        $sale = $this->completeSale(sku: 'RBMARC11L1-B2C', serial: 'SN-IRN-B2C', uqc: 'PCS', buyerGstin: null);
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();

        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $invoice->eInvoiceRecord?->status);
        $this->assertSame('b2c_not_eligible', $invoice->eInvoiceRecord?->response_payload['skip_reason'] ?? null);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(0, $fake->submitCount);
        Http::assertNothingSent();
    }

    public function test_provider_timeout_does_not_generate_twice(): void
    {
        $fake = $this->bindRealMapperFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true], 'corr-timeout'));
        $sale = $this->completeSale(sku: 'RBMARC11L1-TO', serial: 'SN-IRN-TO', uqc: 'PCS', buyerGstin: '07AAAAA0000A1Z5');
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $event = $this->einvoiceEvent($invoice);

        try {
            app(EInvoiceProcessor::class)->process($event);
            $this->fail('Expected recovery after an ambiguous GENERATE.');
        } catch (EInvoiceRecoveryRequiredException) {
        }

        try {
            app(EInvoiceProcessor::class)->process($event);
            $this->fail('Expected recovery to remain required.');
        } catch (EInvoiceRecoveryRequiredException) {
        }

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, $record?->status);
        $this->assertNull($record?->irn);
        Http::assertNothingSent();
    }

    public function test_permanent_provider_failure_does_not_retry_generate(): void
    {
        $fake = $this->bindRealMapperFake(EInvoiceSubmitResult::permanentFailure('fake', ['code' => 'INVALID']));
        $sale = $this->completeSale(sku: 'RBMARC11L1-PF', serial: 'SN-IRN-PF', buyerGstin: '07AAAAA0000A1Z5', uqc: 'PCS');
        $invoice = StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->firstOrFail();
        $event = $this->einvoiceEvent($invoice);

        app(EInvoiceProcessor::class)->process($event);
        app(EInvoiceProcessor::class)->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(EInvoiceRecordStatus::PermanentFailure->value, $record?->status);
        $this->assertNull($record?->irn);
        Http::assertNothingSent();
    }

    private function bindRealMapperFake(?EInvoiceSubmitResult $result = null): FakeEInvoiceGateway
    {
        $fake = $result === null
            ? new FakeEInvoiceGateway(EInvoiceSubmitResult::success(
                provider: 'fake',
                irn: self::IRN,
                ackNo: 'ACK-2411',
                ackDate: '2026-09-11 16:36:00',
                signedQr: self::JWT,
                signedInvoice: 'eyJhbGciOiJ0ZXN0In0.eyJ0ZXN0Ijoic2lnbmVkLWludm9pY2UifQ.test-signature',
                correlationId: 'corr-241',
            ))
            : new FakeEInvoiceGateway($result);
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->forgetInstance(EInvoiceProcessor::class);
        $this->app->forgetInstance(OutboxProcessorService::class);
        $this->app->forgetInstance(StatutoryInvoiceService::class);

        return $fake;
    }

    /**
     * @return InventorySale
     */
    private function completeSale(string $sku, string $serial, ?string $uqc, ?string $buyerGstin)
    {
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Mantra MARC11-L1 Slick Capacitive Fingerprint Scanner',
            'hsn_code' => '84716050',
            'uqc' => $uqc,
            'gst_percentage' => 18,
            'unit_price' => 2300,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, [$serial], $this->actor);

        $statutory = [
            'place_of_supply_state' => 'Delhi',
            'billing_address' => 'NEW-159-OLD-132A, MCD-128, SANT NAGAR',
            'billing_city' => 'New Delhi',
            'billing_state' => 'Delhi',
            'billing_pincode' => '110065',
        ];
        if ($buyerGstin !== null) {
            $statutory['buyer_gstin'] = $buyerGstin;
        }

        return app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Obbless Technologies LLP', 'phone' => '90000'.$this->phoneSeq++],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serial],
            ]],
            paymentMethod: 'UPI',
            paymentReference: 'UPI-241-'.$serial,
            actor: $this->actor,
            statutory: $statutory,
        );
    }

    private function einvoiceEvent(StatutoryInvoice $invoice): OutboxEvent
    {
        return OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();
    }

    private function pdfBinary(StatutoryInvoice $invoice): string
    {
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();

        return app(StatutoryDocumentService::class)->binary($document);
    }

    private function pdfText(StatutoryInvoice $invoice): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $this->pdfBinary($invoice));
    }
}
