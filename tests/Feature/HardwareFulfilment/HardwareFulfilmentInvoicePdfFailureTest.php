<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareNeedsActionSqlQuery;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\HardwareFulfilment\HardwareStatutoryInvoiceIssuer;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoicePdfAssetException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentInvoicePdfFailureTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentInvoiceService $invoices;

    private HardwareFulfilmentWorkflowService $workflow;

    private InventoryProduct $product;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'shipping.enabled' => true,
            'shipping.provider' => 'test',
            'shipping.http_enabled' => false,
            'shipping.pickup_locations.delhi' => 'RADDELHI',
            'shipping.pickup_postcodes.delhi' => '110019',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
        $this->app->instance(ShiprocketGateway::class, new FakeShiprocketGateway);

        $this->seed(RolePermissionSeeder::class);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBIMSOE3L1',
            'name' => 'MSO1300',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => 951,
            ],
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MSO1300',
                'channel_sku' => 'RBIMSOE3L1',
                'notes' => 'Test-only RBP222 serialized map.',
            ],
        );
        $this->operator = $this->operatorUser();

        $this->invoices = app(HardwareFulfilmentInvoiceService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_pdf_generation_failure_still_transitions_to_invoice_issued(): void
    {
        $fulfilment = $this->prepareIssuable('RDE940001', 'DELHI-RETAIL', 1);
        $this->mockDocumentGenerationFailure();

        $invoice = app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertNotNull($fulfilment->fresh()->invoice_issued_at);
        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_retry_after_pdf_failure_is_idempotent_and_does_not_duplicate_invoice(): void
    {
        $fulfilment = $this->prepareIssuable('RDE940002', 'DELHI-RETAIL', 1);
        $this->mockDocumentGenerationFailure();

        $service = app(HardwareFulfilmentInvoiceService::class);
        $first = $service->issueInvoice($fulfilment);
        $second = $service->issueInvoice($fulfilment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_stuck_serials_allocated_state_recovers_on_issue_invoice_retry(): void
    {
        $fulfilment = $this->prepareIssuable('RDE940003', 'DELHI-RETAIL', 1);
        $this->mockDocumentGenerationFailure();

        $service = app(HardwareFulfilmentInvoiceService::class);
        $invoice = $service->issueInvoice($fulfilment);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);

        $fulfilment->forceFill([
            'state' => HardwareFulfilmentState::SerialsAllocated,
            'invoice_issued_at' => null,
        ])->save();

        $recovered = $service->issueInvoice($fulfilment->fresh());

        $this->assertSame($invoice->id, $recovered->id);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_successful_pdf_generation_path_remains_unchanged(): void
    {
        $fulfilment = $this->prepareIssuable('RDE940004', 'DELHI-RETAIL', 1);

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertNotNull($invoice->document);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_issue_after_serials_allocated_survives_pdf_failure(): void
    {
        $fulfilment = $this->prepareIssuable('RDE940005', 'DELHI-RETAIL', 1);
        $this->mockDocumentGenerationFailure();

        app(HardwareStatutoryInvoiceIssuer::class)->issueAfterSerialsAllocated($fulfilment->fresh());

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_invoice_issued_enables_courier_options_in_start_shipment_modal(): void
    {
        $fulfilment = $this->prepareShipmentIssuable('RDE940006', 'DELHI-RETAIL', 1);
        $this->mockDocumentGenerationFailure();
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);
        $this->attachParcelSnapshot($fulfilment->fresh());

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment->fresh(), $ready);

        $this->assertSame('Get Courier Options', $row->nextAction);
        $this->assertTrue($ready->canFetchCourierOptions);
        $this->assertFalse($ready->canCreate);

        $html = view('inventory.hardware-fulfilments.fragments.action-start-shipment', [
            'fulfilment' => $fulfilment->fresh(),
            'ready' => $ready,
            'row' => $row,
            'showUrl' => route('inventory.hardware-fulfilments.show', $fulfilment),
        ])->render();

        $this->assertStringContainsString('Get Courier Options', $html);
        $this->assertStringNotContainsString('Open Fulfilment', $html);
    }

    public function test_recovered_fulfilment_stays_out_of_needs_action_and_in_ready_queue(): void
    {
        $fulfilment = $this->prepareShipmentIssuable('RDE940007', 'DELHI-RETAIL', 1);
        $this->mockDocumentGenerationFailure();
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);
        $this->attachParcelSnapshot($fulfilment->fresh());

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment->fresh(), $ready);
        $from = now('Asia/Kolkata')->startOfDay()->subDays(30);
        $to = now('Asia/Kolkata')->endOfDay();
        $page = app(HardwareNeedsActionSqlQuery::class)->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::NeedsAction,
            $from,
            $to,
            'RDE940007',
            1,
            50,
        );

        $this->assertFalse($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::NeedsAction));
        $this->assertTrue($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::Ready));
        $this->assertSame('ready_for_shipment', $row->stage->value);
        $this->assertNotContains($fulfilment->id, $page['fulfilment_ids']);
    }

    private function attachParcelSnapshot(HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->parcel_snapshot !== null) {
            return;
        }

        $fulfilment->forceFill([
            'parcel_snapshot' => [
                'weight' => 0.2,
                'length' => 10,
                'breadth' => 8,
                'height' => 8,
                'weight_unit' => 'kg',
                'dimension_unit' => 'cm',
                'source' => 'inventory_product_packaging',
                'inventory_product_id' => 22,
                'packaging_id' => 3,
            ],
        ])->save();
    }

    private function mockDocumentGenerationFailure(): void
    {
        $this->mock(StatutoryDocumentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->andThrow(new StatutoryInvoicePdfAssetException('Unable to rasterize invoice asset at public/brand/logo.svg'));
        });

        $this->invoices = app(HardwareFulfilmentInvoiceService::class);
    }

    private function prepareIssuable(string $sourceId, string $branchCode, int $qty): HardwareFulfilment
    {
        $fulfilment = $this->ingestHardware($sourceId, $qty);
        $this->assignBranch($fulfilment, $branchCode);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $this->createAllocatedSerials($fulfilment->fresh(), $qty);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function prepareShipmentIssuable(string $sourceId, string $branchCode, int $qty): HardwareFulfilment
    {
        $fulfilment = $this->ingestHardware($sourceId, $qty);
        $this->assignBranch($fulfilment, $branchCode);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        $serials = [];
        for ($position = 1; $position <= $qty; $position++) {
            $serials[] = sprintf('SN-%s-%03d', $sourceId, $position);
        }

        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->assignBranch($fulfilment->fresh(), $branchCode),
            $serials,
            $this->operator,
        );
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment->fresh(),
            $serials,
            $this->operator,
        );

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function operatorUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $user->id,
            'branch_id' => InventoryBranch::query()->firstOrCreate(
                ['code' => 'DELHI-RETAIL'],
                ['name' => 'DELHI-RETAIL', 'is_active' => true],
            )->id,
        ]);

        return $user;
    }

    private function assignBranch(HardwareFulfilment $fulfilment, string $code): InventoryBranch
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();

        return $branch;
    }

    private function createAllocatedSerials(HardwareFulfilment $fulfilment, int $qty): void
    {
        $item = $fulfilment->commerceOrder?->items->first();
        for ($position = 1; $position <= $qty; $position++) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'commerce_order_item_id' => $item?->id,
                'line_no' => 1,
                'position' => $position,
                'serial_number' => sprintf('SN-%s-%03d', $fulfilment->source_id, $position),
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }
    }

    private function ingestHardware(string $sourceId, int $qty = 1): HardwareFulfilment
    {
        $taxable = round(2583.90 * $qty, 2);
        $tax = round($taxable * 0.18, 2);
        $lineTotal = round($taxable + $tax, 2);
        $payload = [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
                'email' => 'buyer-'.$sourceId.'@example.test',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Madhya Pradesh',
            'shipping_address' => [
                'line1' => '50 Example Street',
                'city' => 'Khandwa',
                'state' => 'Madhya Pradesh',
                'pincode' => '450112',
                'country' => 'India',
            ],
            'parcel' => [
                'weight' => 0.2,
                'length' => 10,
                'breadth' => 8,
                'height' => 8,
            ],
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => $qty,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => $taxable,
                'tax_total' => $tax,
                'line_total' => $lineTotal,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 951,
            ]],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::BOX_SECRET),
        ], $body)->assertCreated();

        return HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
    }
}
