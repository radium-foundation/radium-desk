<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareCommerceStatutoryInvoiceGuard;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceHubHardwareSerialGateTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private StatutoryInvoiceService $invoices;

    private HardwareSerialAllocationService $allocation;

    private HardwareFulfilmentWorkflowService $workflow;

    private User $actor;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
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

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->allocation = app(HardwareSerialAllocationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-HUB-HW-TEST',
            'name' => 'Desk Finance Hub hardware gate product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_paid_hardware_order_without_serials_does_not_mint(): void
    {
        $fulfilment = $this->readyFulfilment('RDE901401', 'DELHI-RETAIL', 1);
        $order = $fulfilment->commerceOrder;
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertFalse(app(StatutoryMintEligibility::class)->evaluateOrder($order)->eligible);
        $this->assertContains(
            HardwareCommerceStatutoryInvoiceGuard::SERIALS_REQUIRED,
            app(StatutoryMintEligibility::class)->evaluateOrder($order)->errors,
        );

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Paid hardware without serials must not mint.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'SERIALS_ALLOCATED',
                implode(' ', $exception->errors()['invoice'] ?? []),
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertNull($order->fresh()->statutory_invoice_id);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
    }

    public function test_incomplete_serial_allocation_does_not_mint(): void
    {
        $fulfilment = $this->ingestHardware('RDE901402', 2);
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $this->createAllocatedSerials($fulfilment->fresh(), 1);

        $order = $fulfilment->fresh()->commerceOrder;
        $this->assertFalse(app(StatutoryMintEligibility::class)->evaluateOrder($order)->eligible);

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Incomplete serials must not mint.');
        } catch (ValidationException $exception) {
            $this->assertNotSame([], $exception->errors());
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_blank_serial_does_not_count_as_complete(): void
    {
        $fulfilment = $this->ingestHardware('RDE901403', 1);
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        HardwareFulfilmentSerial::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'commerce_order_item_id' => $fulfilment->commerceOrder?->items->first()?->id,
            'line_no' => 1,
            'position' => 1,
            'serial_number' => '   ',
            'status' => HardwareFulfilmentSerialStatus::Allocated,
            'allocated_at' => now(),
        ]);

        try {
            $this->invoices->issueFromCommerceOrder($fulfilment->commerceOrder, $this->actor);
            $this->fail('Blank serials must not mint.');
        } catch (ValidationException $exception) {
            $this->assertNotSame([], $exception->errors());
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_required_serial_allocation_mints_one_statutory_invoice(): void
    {
        $fulfilment = $this->readyFulfilment('RDE901404', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-HUB-404']);

        $allocated = $this->allocation->allocateSerials($fulfilment, ['SN-HUB-404'], $this->actor);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $allocated->state);
        $this->assertNotNull($allocated->commerceOrder?->statutory_invoice_id);
        $this->assertSame(
            'statutory:radiumbox_com:commerce_order:RDE901404',
            StatutoryInvoice::query()->value('idempotency_key'),
        );
    }

    public function test_repeated_serial_allocation_still_one_invoice(): void
    {
        $fulfilment = $this->readyFulfilment('RDE901405', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-HUB-405']);

        $first = $this->allocation->allocateSerials($fulfilment, ['SN-HUB-405'], $this->actor);
        $second = $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-HUB-405'], $this->actor);

        $this->assertSame($first->statutory_invoice_id, $second->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_finance_hub_retry_before_serials_does_not_mint(): void
    {
        $fulfilment = $this->readyFulfilment('RDE901406', 'DELHI-RETAIL', 1);
        $order = $fulfilment->commerceOrder;

        $this->actingAs($this->actor)
            ->from(route('finance.invoices.commerce-orders.show', $order))
            ->post(route('finance.invoices.commerce-orders.issue', $order))
            ->assertRedirect(route('finance.invoices.commerce-orders.show', $order))
            ->assertSessionHasErrors();

        try {
            $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);
            $this->fail('Finance Hub retry before serials must not mint.');
        } catch (ValidationException $exception) {
            $this->assertNotSame([], $exception->errors());
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
    }

    public function test_finance_hub_retry_after_serials_returns_the_same_invoice(): void
    {
        $fulfilment = $this->readyFulfilment('RDE901407', 'DELHI-RETAIL', 1, buyerGstin: '07AAAAA0000A1Z5');
        $this->stockAt('DELHI-RETAIL', ['SN-HUB-407']);
        $this->allocation->allocateSerials($fulfilment, ['SN-HUB-407'], $this->actor);

        $fromHub = $this->invoices->issueFromCommerceOrder(
            $fulfilment->fresh()->commerceOrder,
            $this->actor,
        );
        $again = $this->invoices->issueFromCommerceOrder(
            $fulfilment->fresh()->commerceOrder,
            $this->actor,
        );

        $this->assertSame($fromHub->id, $again->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(
            EInvoiceRecordStatus::Queued->value,
            EInvoiceRecord::query()->where('invoice_id', $fromHub->id)->value('status'),
        );
    }

    public function test_concurrent_finance_hub_and_serial_assignment_never_duplicates_or_prematurely_invoices(): void
    {
        $fulfilment = $this->readyFulfilment('RDE901408', 'DELHI-RETAIL', 1, buyerGstin: '07AAAAA0000A1Z5');
        $this->stockAt('DELHI-RETAIL', ['SN-HUB-408']);
        $order = $fulfilment->commerceOrder;

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Finance Hub must not observe READY as invoice-ready.');
        } catch (ValidationException $exception) {
            $this->assertSame(0, StatutoryInvoice::query()->count());
        }

        $allocated = $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-HUB-408'], $this->actor);
        $fromHub = $this->invoices->issueFromCommerceOrder($allocated->commerceOrder, $this->actor);
        $fromHardware = app(HardwareFulfilmentInvoiceService::class)->issueInvoice($allocated->fresh(), $this->actor);
        $replayAllocate = $this->allocation->allocateSerials($allocated->fresh(), ['SN-HUB-408'], $this->actor);

        $this->assertSame($fromHub->id, $fromHardware->id);
        $this->assertSame($fromHub->id, $replayAllocate->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $allocated->fresh()->state);
        $this->assertSame('statutory:radiumbox_com:commerce_order:RDE901408', $fromHub->idempotency_key);
    }

    public function test_b2c_hardware_after_serials_mints_without_irn_outbox(): void
    {
        $fulfilment = $this->readyFulfilment('RDE901409', 'DELHI-RETAIL', 1);
        $this->stockAt('DELHI-RETAIL', ['SN-HUB-409']);
        $this->allocation->allocateSerials($fulfilment, ['SN-HUB-409'], $this->actor);
        $this->invoices->issueFromCommerceOrder($fulfilment->fresh()->commerceOrder, $this->actor);

        $invoice = StatutoryInvoice::query()->firstOrFail();
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame(
            EInvoiceRecordStatus::Skipped->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
    }

    public function test_paid_hardware_without_fulfilment_row_does_not_mint(): void
    {
        $order = $this->paidHardwareCommerceWithoutFulfilment('RDE901410');
        $this->assertTrue(HardwareFulfilmentEligibility::requiresSerialAllocatedInvoice($order));
        $this->assertFalse(app(StatutoryMintEligibility::class)->evaluateOrder($order)->eligible);

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Hardware without fulfilment must not mint.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                HardwareCommerceStatutoryInvoiceGuard::SERIALS_REQUIRED,
                $exception->errors()['invoice'] ?? [],
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_service_commerce_orders_are_not_blocked_by_the_hardware_serial_gate(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-RD-HUB-SVC',
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => 'commerce_order',
            'source_id' => 'RD3512499',
            'source_order_id' => 'RD3512499',
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:RD3512499',
            'payload_hash' => hash('sha256', 'RD3512499'),
            'status' => 'invoice_pending',
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Service Buyer',
            'buyer_gstin' => null,
            'billing_state' => 'Delhi',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-01 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        $this->assertFalse(HardwareFulfilmentEligibility::requiresSerialAllocatedInvoice($order->fresh(['items'])));
        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('statutory:rdservice_in:commerce_order:RD3512499', $invoice->idempotency_key);
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_auto_issue_on_pos_complete_remains_false(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
    }

    private function readyFulfilment(
        string $sourceId,
        string $branchCode,
        int $qty,
        ?string $buyerGstin = null,
    ): HardwareFulfilment {
        $this->mapModel(951);
        $fulfilment = $this->ingestHardware($sourceId, $qty, $buyerGstin);
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

    private function ingestHardware(string $sourceId, int $qty = 1, ?string $buyerGstin = null): HardwareFulfilment
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

    private function paidHardwareCommerceWithoutFulfilment(string $sourceId): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => 'invoice_pending',
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Hardware Buyer',
            'buyer_gstin' => null,
            'billing_state' => 'Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 2583.90,
            'tax_total' => 465.10,
            'order_value' => 3049,
            'ordered_at' => '2026-09-08 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'MSO1300',
            'sku' => '951',
            'qty' => 1,
            'unit_price' => 3049,
            'hsn_sac' => '84716050',
            'gst_percentage' => 18,
            'taxable_value' => 2583.90,
            'tax_total' => 465.10,
            'line_total' => 3049,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => 951,
        ]);

        return $order->fresh(['items']);
    }
}
