<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\EInvoiceRecoveryRequiredException;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEInvoiceGateway;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class InvoiceGenerationIrnSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

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
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
    }

    public function test_offline_pos_hardware_sale_completes_without_whitebooks_then_worker_issues_irn(): void
    {
        Http::preventStrayRequests();
        config(['statutory_invoices.einvoice.provider' => 'whitebooks']);
        $fake = $this->bindCaptureFake();

        $sale = $this->completeB2bSale('OFF-HW-1', idempotencyKey: 'offline-pos-hw-1');

        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame(['OFF-HW-1'], $sale->serials->pluck('serial.serial_number')->filter()->values()->all());
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'OFF-HW-1')->value('status'));
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertNotNull($sale->fresh()->statutory_invoice_id);
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        Http::assertNothingSent();

        config(['statutory_invoices.worker_may_mint' => true]);
        $this->app->forgetInstance(EInvoiceProcessor::class);
        $this->app->forgetInstance(OutboxProcessorService::class);
        app(OutboxProcessorService::class)->process(1);

        $record = EInvoiceRecord::query()->firstOrFail();
        $this->assertSame(1, $fake->submitCount);
        $this->assertTrue($record->hasIssuedIrn());
        $this->assertTrue($record->hasPersistedSignedInvoice());
        $this->assertSame('signed-qr-token', $record->signed_qr);
        $this->assertSame(InventorySaleStatus::Completed, $sale->fresh()->status);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        Http::assertNothingSent();
    }

    public function test_offline_pos_b2c_hardware_sale_invoices_without_irn_outbox(): void
    {
        Http::preventStrayRequests();
        config(['statutory_invoices.einvoice.provider' => 'whitebooks']);
        $fake = $this->bindCaptureFake();

        $sale = $this->completeB2bSale('OFF-B2C-1', buyerGstin: null);

        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(
            'b2c_not_eligible',
            EInvoiceRecord::query()->where('invoice_id', $sale->fresh()->statutory_invoice_id)->value('response_payload')['skip_reason'] ?? null,
        );
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        Http::assertNothingSent();
    }

    public function test_offline_pos_repeated_complete_does_not_duplicate_invoice(): void
    {
        Http::preventStrayRequests();
        config(['statutory_invoices.einvoice.provider' => 'whitebooks']);
        $fake = $this->bindCaptureFake();

        $first = $this->completeB2bSale('OFF-DUP-1', idempotencyKey: 'offline-pos-dup-1');
        $second = $this->completeB2bSale('OFF-DUP-1', idempotencyKey: 'offline-pos-dup-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InventorySale::query()->count());
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        Http::assertNothingSent();
    }

    public function test_whitebooks_timeout_cannot_roll_back_pos_invoice_creation(): void
    {
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true]));

        $sale = $this->completeB2bSale('SEP-TO');

        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(0, $fake->submitCount);
        Http::assertNothingSent();

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(StatutoryInvoiceStatus::Issued, StatutoryInvoice::query()->firstOrFail()->status);
        $this->assertSame(InventorySaleStatus::Completed, $sale->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_whitebooks_5xx_cannot_roll_back_invoice_or_duplicate_generate(): void
    {
        $sale = $this->completeB2bSale('SEP-5XX');
        $invoice = StatutoryInvoice::query()->firstOrFail();
        $event = OutboxEvent::query()
            ->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)
            ->firstOrFail();
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', [
            'reason' => 'generate_provider_5xx',
            'http_status' => 503,
        ]));
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $this->processExpectingRecovery($processor, $event);

        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame($invoice->id, StatutoryInvoice::query()->value('id'));
        $this->assertSame(InventorySaleStatus::Completed, $sale->fresh()->status);
        $this->assertSame(
            EInvoiceRecordStatus::Ambiguous->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );
        Http::assertNothingSent();
    }

    public function test_irn_worker_retry_cannot_duplicate_invoice(): void
    {
        $sale = $this->completeB2bSale('SEP-RETRY');
        $fake = $this->bindLiveFake();

        app(OutboxProcessorService::class)->process(1);
        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(InventorySaleStatus::Completed, $sale->fresh()->status);
        $record = EInvoiceRecord::query()->firstOrFail();
        $this->assertTrue($record->hasIssuedIrn());
        $this->assertTrue($record->hasPersistedSignedInvoice());
        $this->assertSame('signed-qr-token', $record->signed_qr);
        Http::assertNothingSent();
    }

    private function completeB2bSale(
        string $serial,
        ?string $buyerGstin = '07AAAAA0000A1Z5',
        ?string $idempotencyKey = null,
    ): InventorySale {
        $product = InventoryProduct::query()->firstOrCreate(
            ['sku' => 'MFS110-'.$serial],
            [
                'name' => 'Mantra MFS110',
                'hsn_code' => '84716050',
                'gst_percentage' => 18,
                'unit_price' => 100,
                'is_serialized' => true,
                'is_active' => true,
            ],
        );
        if (InventorySerial::query()->where('serial_number', $serial)->doesntExist()) {
            app(InventoryStockService::class)->stockInSerialized($product, $this->branch, [$serial], $this->actor);
        }

        $statutory = [
            'place_of_supply_state' => 'Delhi',
            'billing_address' => '1 Test Street, Delhi',
            'billing_city' => 'New Delhi',
            'billing_state' => 'Delhi',
            'billing_pincode' => '110001',
        ];
        if ($buyerGstin !== null) {
            $statutory['buyer_gstin'] = $buyerGstin;
        }

        return app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000099'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serial],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            idempotencyKey: $idempotencyKey,
            statutory: $statutory,
        );
    }

    private function processExpectingRecovery(EInvoiceProcessor $processor, OutboxEvent $event): void
    {
        try {
            $processor->process($event);
            $this->fail('Expected e-invoice recovery to remain required.');
        } catch (EInvoiceRecoveryRequiredException) {
        }
    }

    private function bindCaptureFake(): FakeEInvoiceGateway
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        $this->app->forgetInstance(EInvoiceProcessor::class);
        $this->app->forgetInstance(OutboxProcessorService::class);

        return $fake;
    }

    private function bindLiveFake(?EInvoiceSubmitResult $result = null): FakeEInvoiceGateway
    {
        $fake = $result === null
            ? FakeEInvoiceGateway::succeeding()
            : (new FakeEInvoiceGateway($result));
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        $this->app->forgetInstance(EInvoiceProcessor::class);
        $this->app->forgetInstance(OutboxProcessorService::class);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'fake',
        ]);

        return $fake;
    }
}
