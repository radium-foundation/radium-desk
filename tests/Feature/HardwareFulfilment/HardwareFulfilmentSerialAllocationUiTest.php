<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Enums\InventorySerialStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackOutboxWriter;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HardwareFulfilmentSerialAllocationUiTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentWorkflowService $workflow;

    private User $operator;

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

        $this->seed(RolePermissionSeeder::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->operator = $this->hardwareOperator(['DELHI-RETAIL', 'MUMBAI']);
    }

    public function test_authorized_operator_can_open_allocation_page(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900801');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Allocate Serial')
            ->assertSee('RDE900801');
    }

    public function test_unauthorized_users_cannot_open_or_allocate(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900802');
        $this->stockAt('DELHI-RETAIL', ['SN-UI-802']);
        $itemId = $this->itemId($fulfilment);

        $this->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertRedirect();

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(RolePermissionSeeder::PERMISSION_INVENTORY_VIEW);

        $this->actingAs($viewer)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->getJson(route('inventory.hardware-fulfilments.serials.search', [
                'fulfilment' => $fulfilment->id,
                'commerce_order_item_id' => $itemId,
                'q' => 'SN-UI-802',
            ]))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), [
                'serials' => [$itemId => ['SN-UI-802']],
            ])
            ->assertForbidden();

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
    }

    public function test_page_shows_fulfilment_product_quantity_and_status(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900803');
        $fulfilment->forceFill(['support_order_id' => 51463])->save();

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('RDE900803')
            ->assertSee($fulfilment->commerceOrder?->order_no)
            ->assertSee('Support 51463')
            ->assertSee('MFS110')
            ->assertSee('RBMFS110L1')
            ->assertSee('model 946')
            ->assertSee('1 serial required')
            ->assertSee('READY FOR FULFILMENT')
            ->assertSee('Derived from selected serial')
            ->assertDontSee('Create Shipment')
            ->assertDontSee('Assign AWB')
            ->assertDontSee('name="claimed_branch"', false);
    }

    public function test_serial_search_returns_available_stock_with_branch(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900804');
        $this->stockAt('DELHI-RETAIL', ['SN-UI-804-D']);
        $this->stockAt('MUMBAI', ['SN-UI-804-M']);
        $itemId = $this->itemId($fulfilment);

        $this->actingAs($this->operator)
            ->getJson(route('inventory.hardware-fulfilments.serials.search', [
                'fulfilment' => $fulfilment->id,
                'commerce_order_item_id' => $itemId,
                'q' => 'SN-UI-804',
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'serials')
            ->assertJsonPath('serials.0.serial_number', 'SN-UI-804-D')
            ->assertJsonPath('serials.0.branch_code', 'DELHI-RETAIL')
            ->assertJsonPath('serials.1.serial_number', 'SN-UI-804-M')
            ->assertJsonPath('serials.1.branch_code', 'MUMBAI');
    }

    public function test_search_does_not_return_wrong_model_or_unavailable_serials(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900805');
        $this->stockAt('DELHI-RETAIL', ['SN-UI-805-OK', 'SN-UI-805-SOLD']);
        InventorySerial::query()->where('serial_number', 'SN-UI-805-SOLD')->update([
            'status' => InventorySerialStatus::Sold,
        ]);
        $other = InventoryProduct::query()->create([
            'sku' => 'OTHER-SKU',
            'name' => 'Other product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized(
            $other,
            $this->branch('DELHI-RETAIL'),
            ['SN-UI-805-WRONG'],
            $this->operator,
        );

        $response = $this->actingAs($this->operator)
            ->getJson(route('inventory.hardware-fulfilments.serials.search', [
                'fulfilment' => $fulfilment->id,
                'commerce_order_item_id' => $this->itemId($fulfilment),
                'q' => 'SN-UI-805',
            ]))
            ->assertOk();

        $numbers = collect($response->json('serials'))->pluck('serial_number')->all();
        $this->assertSame(['SN-UI-805-OK'], $numbers);
    }

    public function test_wrong_model_serial_is_rejected(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900806');
        $other = InventoryProduct::query()->create([
            'sku' => 'WRONG-MODEL',
            'name' => 'Wrong model',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized(
            $other,
            $this->branch('DELHI-RETAIL'),
            ['SN-UI-806-WRONG'],
            $this->operator,
        );

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), [
                'serials' => [$this->itemId($fulfilment) => ['SN-UI-806-WRONG']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('serials');

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertNoSideEffects();
    }

    public function test_unavailable_and_already_sold_serials_are_rejected(): void
    {
        $first = $this->readyFulfilment('RDE900807');
        $second = $this->readyFulfilment('RDE900808');
        $this->stockAt('DELHI-RETAIL', ['SN-UI-807-SOLD', 'SN-UI-807-HOLD']);
        InventorySerial::query()->where('serial_number', 'SN-UI-807-HOLD')->update([
            'status' => InventorySerialStatus::Reserved,
        ]);

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $first), [
                'serials' => [$this->itemId($first) => ['SN-UI-807-SOLD']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $first));

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $second))
            ->post(route('inventory.hardware-fulfilments.serials.store', $second), [
                'serials' => [$this->itemId($second) => ['SN-UI-807-SOLD']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $second))
            ->assertSessionHasErrors('serials');

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $second))
            ->post(route('inventory.hardware-fulfilments.serials.store', $second), [
                'serials' => [$this->itemId($second) => ['SN-UI-807-HOLD']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $second))
            ->assertSessionHasErrors('serials');

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $second->fresh()->state);
        $this->assertSame(1, HardwareFulfilmentSerial::query()->count());
    }

    public function test_duplicate_retry_is_idempotent_and_shows_derived_branch(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900809');
        $this->stockAt('DELHI-RETAIL', ['SN-UI-809']);
        $itemId = $this->itemId($fulfilment);
        $payload = ['serials' => [$itemId => ['SN-UI-809']]];

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), $payload)
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), $payload)
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $fresh = $fulfilment->fresh(['fulfilmentBranch', 'serials']);
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fresh->state);
        $this->assertSame('DELHI-RETAIL', $fresh->fulfilmentBranch?->code);
        $this->assertSame(1, HardwareFulfilmentSerial::query()->count());

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fresh))
            ->assertOk()
            ->assertSee('SN-UI-809')
            ->assertSee('DELHI-RETAIL')
            ->assertSee('SERIALS ALLOCATED')
            ->assertDontSee('Allocate Serial');

        $this->assertNoSideEffects();
    }

    public function test_successful_allocation_derives_branch_and_creates_no_invoice_shipment_or_callback(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900810');
        $this->stockAt('MUMBAI', ['SN-UI-810']);

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), [
                'serials' => [$this->itemId($fulfilment) => ['SN-UI-810']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status');

        $fresh = $fulfilment->fresh(['fulfilmentBranch']);
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fresh->state);
        $this->assertSame('MUMBAI', $fresh->fulfilmentBranch?->code);
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'SN-UI-810')->value('status'));
        $this->assertNoSideEffects();
        $callback = OutboxEvent::query()
            ->where('event_type', HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE)
            ->first();
        $this->assertTrue($callback === null || $callback->status === OutboxEventStatus::Pending);
        $this->assertTrue($callback === null || (int) $callback->attempts === 0);
    }

    public function test_quantity_must_match_exactly(): void
    {
        $single = $this->readyFulfilment('RDE900811', qty: 1);
        $double = $this->readyFulfilment('RDE900812', qty: 2);
        $this->stockAt('DELHI-RETAIL', ['SN-UI-811-A', 'SN-UI-811-B', 'SN-UI-812-A', 'SN-UI-812-B']);

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $single))
            ->post(route('inventory.hardware-fulfilments.serials.store', $single), [
                'serials' => [$this->itemId($single) => ['SN-UI-811-A', 'SN-UI-811-B']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $single))
            ->assertSessionHasErrors('serials');

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $double))
            ->post(route('inventory.hardware-fulfilments.serials.store', $double), [
                'serials' => [$this->itemId($double) => ['SN-UI-812-A']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $double))
            ->assertSessionHasErrors('serials');

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $single->fresh()->state);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $double->fresh()->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertNoSideEffects();
    }

    public function test_competing_http_allocation_cannot_reuse_the_same_serial(): void
    {
        $first = $this->readyFulfilment('RDE900813');
        $second = $this->readyFulfilment('RDE900814');
        $this->stockAt('DELHI-RETAIL', ['SN-UI-813', 'SN-UI-814']);

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $first), [
                'serials' => [$this->itemId($first) => ['SN-UI-813']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $first));

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $second))
            ->post(route('inventory.hardware-fulfilments.serials.store', $second), [
                'serials' => [$this->itemId($second) => ['SN-UI-813']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $second))
            ->assertSessionHasErrors('serials');

        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $first->fresh()->state);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $second->fresh()->state);
        $this->assertSame(1, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, Shipment::query()->count());
    }

    /**
     * @param  list<string>  $branchCodes
     */
    private function hardwareOperator(array $branchCodes): User
    {
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        foreach ($branchCodes as $code) {
            InventoryUserBranch::query()->firstOrCreate([
                'user_id' => $operator->id,
                'branch_id' => $this->branch($code)->id,
            ]);
        }

        return $operator;
    }

    private function readyFulfilment(string $sourceId, int $qty = 1): HardwareFulfilment
    {
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => 946,
            ],
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MFS110',
                'channel_sku' => 'RBMFS110L1',
                'notes' => 'Test-only UI map. Not a production write.',
            ],
        );

        $fulfilment = $this->ingestHardware($sourceId, $qty);
        $this->assertNull($fulfilment->fulfilment_branch_id);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): InventoryBranch
    {
        $branch = $this->branch($branchCode);
        app(InventoryStockService::class)->stockInSerialized($this->product, $branch, $serials, $this->operator);

        return $branch;
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }

    private function itemId(HardwareFulfilment $fulfilment): int
    {
        return (int) $fulfilment->commerceOrder?->items->first()?->id;
    }

    private function assertNoSideEffects(): void
    {
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(
            0,
            OutboxEvent::query()
                ->where('event_type', HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE)
                ->where(function ($query): void {
                    $query->where('status', '!=', OutboxEventStatus::Pending)
                        ->orWhere('attempts', '>', 0);
                })
                ->count(),
        );
    }

    private function ingestHardware(string $sourceId, int $qty): HardwareFulfilment
    {
        $taxable = round(2160.17 * $qty, 2);
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
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'MFS110',
                'sku' => 'RBMFS110L1',
                'qty' => $qty,
                'unit_price' => 2549,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => $taxable,
                'tax_total' => $tax,
                'line_total' => $lineTotal,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 946,
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
