<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Enums\InventoryMovementType;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryStockBalance;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackOutboxWriter;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwarePhysicalStockCommitment;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareMixedSerializedFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareSerialAllocationService $allocation;

    private HardwareFulfilmentWorkflowService $workflow;

    private User $actor;

    private InventoryProduct $scanner;

    private InventoryProduct $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->allocation = app(HardwareSerialAllocationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->scanner = InventoryProduct::query()->create([
            'sku' => 'RBMFS110MIX',
            'name' => 'MFS110 scanner',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->cable = InventoryProduct::query()->create([
            'sku' => 'RBMFSTYPEC',
            'name' => 'Type-C cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 299,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $this->scanner->id,
            'catalog_sku' => 'MFS110',
            'channel_sku' => '946',
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1409,
            'inventory_product_id' => $this->cable->id,
            'catalog_sku' => 'PMTMFSCCBL',
            'channel_sku' => '1409',
        ]);
    }

    public function test_cable_only_order_still_allocates_without_serials(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-CABLE', [
            $this->line(1409, 1),
        ]);
        $branch = $this->stockCableQty('DELHI-RETAIL', 2);
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();

        $allocated = $this->allocation->allocateQuantityStock($fulfilment, $this->actor);

        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $allocated->state);
        $this->assertSame([], $this->workflow->allocatedSerialNumbers($allocated));
    }

    public function test_mixed_scanner_and_cable_auto_invoice_mints_one_statutory_invoice_with_both_lines(): void
    {
        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);

        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-INV', [
            $this->line(946, 1),
            $this->line(1409, 1),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-MIX-INV'], $this->actor);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 2, $this->actor);

        $scannerItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 946)->id;
        $order = $fulfilment->commerceOrder;
        $orderTaxTotal = round((float) $order->items->sum(fn ($item) => (float) $item->tax_total), 2);
        $orderLineTotal = round((float) $order->items->sum(fn ($item) => (float) $item->line_total), 2);

        $allocated = $this->allocation->allocate($fulfilment, [$scannerItemId => ['SN-MIX-INV']], $this->actor);

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $allocated->state);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $invoice = StatutoryInvoice::query()->firstOrFail();
        $this->assertSame(2, $invoice->items()->count());
        $this->assertEqualsWithDelta($orderLineTotal, (float) $invoice->invoice_value, 0.02);
        $this->assertEqualsWithDelta($orderTaxTotal, (float) $invoice->tax_total, 0.02);
        $this->assertSame(
            2,
            OutboxEvent::query()->where('event_type', HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE)->count(),
        );
        $this->assertSame(['SN-MIX-INV'], $this->workflow->allocatedSerialNumbers($allocated));
    }

    public function test_mixed_scanner_and_cable_allocates_serials_only_for_scanner(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-2010', [
            $this->line(946, 1),
            $this->line(1409, 1),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-MIX-001'], $this->actor);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 3, $this->actor);

        $scannerItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 946)->id;
        $cableItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 1409)->id;

        $allocated = $this->allocation->allocate(
            $fulfilment,
            [$scannerItemId => ['SN-MIX-001']],
            $this->actor,
        );

        $commitment = app(HardwarePhysicalStockCommitment::class);
        $this->assertTrue($commitment->isStockCommitted($allocated, $allocated->commerceOrder));
        $this->assertSame(['SN-MIX-001'], $this->workflow->allocatedSerialNumbers($allocated));
        $this->assertSame(1, $commitment->quantityCommittedQty($allocated, $cableItemId));
        $this->assertSame(0, $commitment->quantityCommittedQty($allocated, $scannerItemId));
    }

    public function test_serialized_line_without_serial_blocks_allocation(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-BLOCK', [
            $this->line(946, 1),
            $this->line(1409, 1),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 2, $this->actor);
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);

        $this->expectException(ValidationException::class);
        $this->allocation->allocate($fulfilment, [], $this->actor);
    }

    public function test_mixed_order_serial_submission_for_cable_line_is_rejected(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-REJECT-CABLE-SERIAL', [
            $this->line(946, 1),
            $this->line(1409, 1),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-MIX-002'], $this->actor);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 2, $this->actor);

        $scannerItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 946)->id;
        $cableItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 1409)->id;

        $this->expectException(ValidationException::class);
        $this->allocation->allocate($fulfilment, [
            $scannerItemId => ['SN-MIX-002'],
            $cableItemId => ['SN-FAKE-CABLE'],
        ], $this->actor);
    }

    public function test_mixed_multi_line_quantities_allocate_per_line(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-MULTI', [
            $this->line(946, 2),
            $this->line(1409, 3),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-A', 'SN-B'], $this->actor);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 5, $this->actor);

        $scannerItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 946)->id;
        $cableItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 1409)->id;

        $allocated = $this->allocation->allocate(
            $fulfilment,
            [$scannerItemId => ['SN-A', 'SN-B']],
            $this->actor,
        );

        $commitment = app(HardwarePhysicalStockCommitment::class);
        $this->assertTrue($commitment->isStockCommitted($allocated, $allocated->commerceOrder));
        $this->assertSame(['SN-A', 'SN-B'], $this->workflow->allocatedSerialNumbers($allocated));
        $this->assertSame(3, $commitment->quantityCommittedQty($allocated, $cableItemId));
    }

    public function test_mixed_order_insufficient_serial_stock_is_rejected(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-NO-SERIAL-STOCK', [
            $this->line(946, 2),
            $this->line(1409, 1),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-ONLY-ONE'], $this->actor);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 2, $this->actor);

        $scannerItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 946)->id;

        $this->expectException(ValidationException::class);
        $this->allocation->allocate($fulfilment, [$scannerItemId => ['SN-ONLY-ONE', 'SN-MISSING']], $this->actor);
    }

    public function test_serialized_only_order_behaviour_unchanged(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-SERIAL-ONLY', [
            $this->line(946, 1),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-ONLY'], $this->actor);

        $scannerItemId = (int) $fulfilment->commerceOrder->items->first()->id;
        $allocated = $this->allocation->allocate($fulfilment, [$scannerItemId => ['SN-ONLY']], $this->actor);

        $this->assertSame(['SN-ONLY'], $this->workflow->allocatedSerialNumbers($allocated));
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $allocated->state);
    }

    public function test_mixed_allocation_records_quantity_and_serial_stock_movements(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-MOVES', [
            $this->line(946, 1),
            $this->line(1409, 2),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-MOVE'], $this->actor);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 4, $this->actor);

        $scannerItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 946)->id;

        $this->allocation->allocate($fulfilment, [$scannerItemId => ['SN-MOVE']], $this->actor);

        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('product_id', $this->scanner->id)
                ->where('type', InventoryMovementType::Sale)
                ->count(),
        );
        $this->assertSame(
            -2,
            (int) InventoryMovement::query()
                ->where('product_id', $this->cable->id)
                ->where('type', InventoryMovementType::Sale)
                ->sum('qty'),
        );
        $balance = InventoryStockBalance::query()
            ->where('product_id', $this->cable->id)
            ->where('branch_id', $branch->id)
            ->first();
        $this->assertSame(2, (int) $balance?->available_qty);
    }

    public function test_action_dialog_lists_quantity_line_without_serial_picker(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-UI', [
            $this->line(946, 1),
            $this->line(1409, 1),
        ]);
        $branch = $this->branch('DELHI-RETAIL');
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInSerialized($this->scanner, $branch, ['SN-UI'], $this->actor);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, 2, $this->actor);

        $response = $this->actingAs($this->actor)
            ->get(route('inventory.hardware-fulfilments.action-dialog', $fulfilment));

        $response->assertOk();
        $this->assertSame(1, substr_count((string) $response->getContent(), 'data-hardware-serial-picker'));
        $response->assertSee('deducted automatically with serial allocation', false);
        $response->assertSee('Serials allocated: 0 / 1', false);
        $response->assertDontSee('Product 2', false);
        $response->assertDontSee('Serials allocated: 0 / 2', false);
        $response->assertDontSee('Total required: 2', false);
    }

    public function test_requirements_mark_non_serialized_lines_correctly(): void
    {
        $fulfilment = $this->readyMixedFulfilment('RBP-MIX-REQ', [
            $this->line(946, 1),
            $this->line(1409, 1),
        ]);

        $requirements = $this->allocation->requirements($fulfilment);
        $byItem = collect($requirements)->keyBy('commerce_order_item_id');

        $scannerItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 946)->id;
        $cableItemId = (int) $fulfilment->commerceOrder->items->firstWhere('model_id', 1409)->id;

        $this->assertTrue($byItem[$scannerItemId]['requires_serial']);
        $this->assertFalse($byItem[$cableItemId]['requires_serial']);
    }

    /**
     * @param  list<array{model_id: int, qty: int}>  $lines
     */
    private function readyMixedFulfilment(string $sourceId, array $lines): HardwareFulfilment
    {
        $fulfilment = $this->ingestHardware($sourceId, $lines);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    /**
     * @return array{model_id: int, qty: int}
     */
    private function line(int $modelId, int $qty): array
    {
        return ['model_id' => $modelId, 'qty' => $qty];
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }

    private function stockCableQty(string $branchCode, int $qty): InventoryBranch
    {
        $branch = $this->branch($branchCode);
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        app(InventoryStockService::class)->stockInQuantity($this->cable, $branch, $qty, $this->actor);

        return $branch;
    }

    /**
     * @param  list<array{model_id: int, qty: int}>  $lines
     */
    private function ingestHardware(string $sourceId, array $lines): HardwareFulfilment
    {
        $payloadLines = [];
        foreach ($lines as $line) {
            $modelId = $line['model_id'];
            $qty = $line['qty'];
            $unit = $modelId === 1409 ? 299 : 2549;
            $taxable = round(($unit / 1.18) * $qty, 2);
            $tax = round($taxable * 0.18, 2);
            $lineTotal = round($taxable + $tax, 2);
            $payloadLines[] = [
                'description' => $modelId === 1409 ? 'Biometric Replacement Cable' : 'MFS110',
                'sku' => (string) $modelId,
                'qty' => $qty,
                'unit_price' => $unit,
                'hsn_sac' => $modelId === 1409 ? '85444299' : '84716050',
                'gst_percentage' => 18,
                'taxable_value' => $taxable,
                'tax_total' => $tax,
                'line_total' => $lineTotal,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => $modelId,
            ];
        }

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
                'name' => 'Mixed Buyer',
                'phone' => '9000000107',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => $payloadLines,
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
