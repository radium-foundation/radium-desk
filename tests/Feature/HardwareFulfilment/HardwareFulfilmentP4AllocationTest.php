<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareSkuMapService;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareFulfilmentP4AllocationTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareSerialAllocationService $allocation;

    private HardwareFulfilmentWorkflowService $workflow;

    private User $actor;

    private InventoryProduct $product;

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
            'hardware_fulfilment.sku_map' => [],
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->allocation = app(HardwareSerialAllocationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-MSO-TEST',
            'name' => 'Desk MSO test product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_valid_allocation_persists_exact_serials_and_marks_stock_sold(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900401', 'DELHI-RETAIL', 2);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-001', 'SN-P4-002', 'SN-P4-003']);

        $allocated = $this->allocation->allocateSerials($fulfilment, ['SN-P4-001', 'SN-P4-002'], $this->actor);

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $allocated->state);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(
            ['SN-P4-001', 'SN-P4-002'],
            $this->workflow->allocatedSerialNumbers($allocated),
        );
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'SN-P4-001')->value('status'));
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-P4-003')->value('status'));
        $this->assertSame(2, HardwareFulfilmentSerial::query()->where('status', HardwareFulfilmentSerialStatus::Allocated)->count());
    }

    public function test_too_few_serials_remain_ready_for_fulfilment(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900402', 'DELHI-RETAIL', 2);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-010', 'SN-P4-011']);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-P4-010'], $this->actor);
            $this->fail('Too few serials must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Too few serials', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-P4-010')->value('status'));
    }

    public function test_too_many_serials_are_rejected(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900403', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-020', 'SN-P4-021']);

        $this->expectException(ValidationException::class);
        $this->allocation->allocateSerials($fulfilment, ['SN-P4-020', 'SN-P4-021'], $this->actor);
    }

    public function test_duplicate_serial_in_same_fulfilment_is_rejected(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900404', 'DELHI-RETAIL', 2);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-030', 'SN-P4-031']);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-P4-030', 'sn-p4-030'], $this->actor);
            $this->fail('Duplicates must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Duplicate serial', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
    }

    public function test_already_sold_or_allocated_serial_is_rejected(): void
    {
        $first = $this->readyFulfilment('RDE900405', 'DELHI-RETAIL', 1);
        $second = $this->readyFulfilment('RDE900406', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-040', 'SN-P4-041']);
        $this->allocation->allocateSerials($first, ['SN-P4-040'], $this->actor);

        try {
            $this->allocation->allocateSerials($second, ['SN-P4-040'], $this->actor);
            $this->fail('Already allocated serial must fail.');
        } catch (ValidationException $exception) {
            $this->assertNotSame([], $exception->errors());
        }

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $second->fresh()->state);
        $this->assertSame(1, HardwareFulfilmentSerial::query()->count());
    }

    public function test_wrong_physical_branch_is_rejected(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900407', 'DELHI-RETAIL', 1);
        $this->stockAt('MUMBAI', ['SN-P4-050']);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-P4-050'], $this->actor);
            $this->fail('Wrong branch must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not available at DELHI-RETAIL', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-P4-050')->value('status'));
    }

    public function test_invoice_cannot_mint_before_allocation(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900408', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-060']);

        try {
            app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);
            $this->fail('Invoice must not mint before SERIALS_ALLOCATED.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('SERIALS_ALLOCATED', implode(' ', $exception->errors()['invoice'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
    }

    public function test_retry_of_same_allocation_is_idempotent(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900409', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-070']);

        $first = $this->allocation->allocateSerials($fulfilment, ['SN-P4-070'], $this->actor);
        $second = $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-P4-070'], $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $second->state);
        $this->assertSame(1, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(['SN-P4-070'], $this->workflow->allocatedSerialNumbers($second));
    }

    public function test_competing_allocation_cannot_reuse_the_same_serial(): void
    {
        $a = $this->readyFulfilment('RDE900410', 'DELHI-RETAIL', 1);
        $b = $this->readyFulfilment('RDE900411', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-080', 'SN-P4-081']);
        $this->allocation->allocateSerials($a, ['SN-P4-080'], $this->actor);

        $this->expectException(ValidationException::class);
        $this->allocation->allocateSerials($b, ['SN-P4-080'], $this->actor);
    }

    public function test_partial_failure_does_not_mark_allocation_complete(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900412', 'DELHI-RETAIL', 2);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-090']);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-P4-090', 'SN-P4-MISSING'], $this->actor);
            $this->fail('Partial allocation must fail.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-P4-090')->value('status'));
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_missing_sku_map_fails_closed_and_does_not_guess_box_sku(): void
    {
        $this->assertSame([], config('hardware_fulfilment.sku_map'));
        $this->assertSame(0, ChannelSkuMap::query()->count());

        $fulfilment = $this->readyFulfilment('RDE900413', 'DELHI-RETAIL', 1, map: false);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-100']);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-P4-100'], $this->actor);
            $this->fail('Missing map must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('does not invent mappings', implode(' ', $exception->errors()['sku_map'] ?? []));
        }

        $this->expectException(ValidationException::class);
        app(HardwareSkuMapService::class)->requireProduct(StatutoryInvoiceChannel::RadiumBoxCom, 951);
    }

    public function test_owner_map_resolves_box_model_to_desk_product(): void
    {
        $this->mapModel(951);
        $product = app(HardwareSkuMapService::class)->requireProduct(StatutoryInvoiceChannel::RadiumBoxCom, 951);

        $this->assertSame($this->product->id, $product->id);
        $this->assertSame('DESK-MSO-TEST', $product->sku);
        $this->assertNotSame('951', $product->sku);
        $this->assertNotSame('MSO1300', $product->sku);
    }

    public function test_bundled_rd_stays_an_annotation_and_p3_consumes_p4_serials(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900414', 'DELHI-RETAIL', 1, rdserviceid: 88);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-110']);
        $this->allocation->allocateSerials($fulfilment, ['SN-P4-110'], $this->actor);

        $invoice = app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment->fresh());
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);

        $this->assertSame(1, $invoice->items->count());
        $this->assertStringContainsString('bundled RD #88', (string) $invoice->items->first()?->description);
        $this->assertSame(3049.0, (float) $invoice->items->first()?->line_total);
        $this->assertStringContainsString('SN-P4-110', $pdf);
        $this->assertSame(['SN-P4-110'], $fulfilment->fresh()->metadata['invoice_serials'] ?? []);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
    }

    public function test_frozen_pending_orders_cannot_be_allocated(): void
    {
        $this->assertSame([
            'RDE318360',
            'RDE318367',
            'RDE318378',
            'RDE318379',
            'RDE318382',
            'RDE318388',
            'RDE318391',
        ], HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS);

        foreach (HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS as $sourceId) {
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
        }

        $this->assertSame(0, CommerceOrder::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, HardwareFulfilment::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());

        try {
            $this->allocation->allocateSerials(new HardwareFulfilment(['source_id' => 'RDE318360']), ['SN-FROZEN'], $this->actor);
            $this->fail('Frozen source ids must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Frozen', implode(' ', $exception->errors()['fulfilment'] ?? []));
        }
    }

    public function test_picker_http_requires_hardware_fulfilment_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fulfilment = $this->readyFulfilment('RDE900415', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-120']);

        $this->actingAs($this->actor)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertForbidden();

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $operator->id,
            'branch_id' => InventoryBranch::query()->where('code', 'DELHI-RETAIL')->value('id'),
        ]);

        $this->actingAs($operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('DESK-MSO-TEST')
            ->assertSee('RDE900415');

        $itemId = (int) $fulfilment->commerceOrder?->items->first()?->id;
        $this->actingAs($operator)
            ->getJson(route('inventory.hardware-fulfilments.serials.search', [
                'fulfilment' => $fulfilment->id,
                'commerce_order_item_id' => $itemId,
                'q' => 'SN-P4-120',
            ]))
            ->assertOk()
            ->assertJsonPath('serials.0.serial_number', 'SN-P4-120');

        $this->actingAs($operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), [
                'serials' => [$itemId => ['SN-P4-120']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_repeated_http_allocate_does_not_duplicate_the_invoice(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fulfilment = $this->readyFulfilment('RDE900416', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-121']);
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $operator->id,
            'branch_id' => InventoryBranch::query()->where('code', 'DELHI-RETAIL')->value('id'),
        ]);
        $itemId = (int) $fulfilment->commerceOrder?->items->first()?->id;
        $payload = ['serials' => [$itemId => ['SN-P4-121']]];

        $this->actingAs($operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), $payload)
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));
        $this->actingAs($operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), $payload)
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
    }

    public function test_allocate_and_issue_invoice_converge_on_one_invoice(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900417', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-122']);

        $this->allocation->allocateSerials($fulfilment, ['SN-P4-122'], $this->actor);
        $first = app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment->fresh());
        $second = app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_b2c_hardware_invoice_does_not_enter_irn_outbox(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900418', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-P4-123']);
        $this->allocation->allocateSerials($fulfilment, ['SN-P4-123'], $this->actor);

        $invoice = StatutoryInvoice::query()->firstOrFail();
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame(
            EInvoiceRecordStatus::Skipped->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
    }

    public function test_b2b_hardware_invoice_enters_irn_outbox_once(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900419', 'DELHI-RETAIL', 1, buyerGstin: '07AAAAA0000A1Z5');
        $this->stockAt('DELHI-RETAIL', ['SN-P4-124']);
        $this->allocation->allocateSerials($fulfilment, ['SN-P4-124'], $this->actor);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment->fresh());

        $invoice = StatutoryInvoice::query()->firstOrFail();
        $this->assertSame('07AAAAA0000A1Z5', $invoice->buyer_gstin);
        $this->assertSame(
            EInvoiceRecordStatus::Queued->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );
        $this->assertSame(1, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    private function readyFulfilment(
        string $sourceId,
        string $branchCode,
        int $qty,
        bool $map = true,
        ?int $rdserviceid = null,
        ?string $buyerGstin = null,
    ): HardwareFulfilment {
        if ($map) {
            $this->mapModel(951);
        }

        $fulfilment = $this->ingestHardware($sourceId, $qty, $rdserviceid, $buyerGstin);
        $this->assignBranch($fulfilment, $branchCode);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function mapModel(int $modelId): void
    {
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
            ],
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MSO1300',
                'channel_sku' => '951',
                'notes' => 'Test-only Owner map. Not a production P0-M1 row.',
            ],
        );
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): InventoryBranch
    {
        $branch = $this->assignBranch(new HardwareFulfilment, $branchCode);
        app(InventoryStockService::class)->stockInSerialized($this->product, $branch, $serials, $this->actor);

        return $branch;
    }

    private function assignBranch(?HardwareFulfilment $fulfilment, string $code): InventoryBranch
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        if ($fulfilment?->exists) {
            $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();
        }

        return $branch;
    }

    private function ingestHardware(string $sourceId, int $qty = 1, ?int $rdserviceid = null, ?string $buyerGstin = null): HardwareFulfilment
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
                'gstin' => $buyerGstin,
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
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
                'rdserviceid' => $rdserviceid,
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
