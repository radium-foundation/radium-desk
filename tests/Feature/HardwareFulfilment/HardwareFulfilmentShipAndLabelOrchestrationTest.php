<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\HardwareFulfilment\HardwareShipmentOrchestrationService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentShipAndLabelOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private FakeShiprocketGateway $fake;

    private User $admin;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        Http::fake();
        Http::preventStrayRequests();
        $this->fake = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->fake);
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.callback.enabled' => false,
            'hardware_fulfilment.sku_map' => [],
            'shipping.enabled' => true,
            'shipping.provider' => 'test',
            'shipping.http_enabled' => false,
            'shipping.auto_select_recommended_courier' => false,
            'shipping.pickup_locations.delhi' => 'RADDELHI',
            'shipping.pickup_locations.mumbai' => 'RADIUMUM',
            'shipping.pickup_postcodes.delhi' => '110001',
            'shipping.pickup_postcodes.mumbai' => '400001',
            'shipping.courier_options_ttl_seconds' => 900,
            'shipping.channel_id' => '',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN, ['DELHI-RETAIL', 'MUMBAI']);
    }

    public function test_action_dialog_prepares_courier_options_and_offers_confirm_then_ship_and_label(): void
    {
        $this->fake->recommendedCourierId = '44';
        $fulfilment = $this->invoicedFulfilment('RDE950001', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.action-dialog', $fulfilment))
            ->assertOk()
            ->assertSee('Confirm Courier &amp; Continue', false)
            ->assertSee('Shiprocket Recommended')
            ->assertDontSee('Ship &amp; Generate Label', false);

        $this->assertSame(1, $this->fake->courierLists);

        $this->actingAs($this->admin)
            ->postJson(route('inventory.hardware-fulfilments.confirm-recommended-courier.store', $fulfilment->fresh()), [], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('next_action', 'Ship & Generate Label');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.action-dialog', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Ship &amp; Generate Label', false);
    }

    public function test_auto_select_enables_one_click_ship_and_label_after_dialog_prep(): void
    {
        config(['shipping.auto_select_recommended_courier' => true]);
        $this->fake->recommendedCourierId = '44';
        $fulfilment = $this->invoicedFulfilment('RDE950002', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.action-dialog', $fulfilment))
            ->assertOk()
            ->assertSee('Ship &amp; Generate Label', false)
            ->assertDontSee('Confirm Courier &amp; Continue', false);

        $this->actingAs($this->admin)
            ->postJson(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()), [], [
                'Accept' => 'application/json',
            ])
            ->assertOk();

        $shipment = Shipment::query()->firstOrFail();
        $this->assertTrue($shipment->isBound());
        $this->assertNotNull($shipment->awb);
        $this->assertNotNull($shipment->label_url);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->fresh()->state);
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame(1, $this->fake->labels);
    }

    public function test_two_click_path_completes_shipment_awb_and_label(): void
    {
        $this->fake->recommendedCourierId = '44';
        $fulfilment = $this->invoicedFulfilment('RDE950003', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.confirm-recommended-courier.store', $fulfilment))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status');

        $shipment = Shipment::query()->firstOrFail();
        $this->assertNotNull($shipment->label_url);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_manual_courier_override_remains_available(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE950004', 'DELHI-RETAIL');
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect();

        $options = $fulfilment->fresh()->courier_options_snapshot['options'] ?? [];
        $alternateId = (string) ($options[1]['courier_id'] ?? $options[0]['courier_id']);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier.store', $fulfilment->fresh()), [
                'courier_id' => $alternateId,
            ])
            ->assertRedirect();

        $this->assertSame($alternateId, $fulfilment->fresh()->selected_courier_id);
    }

    public function test_ship_and_label_is_idempotent_when_label_already_exists(): void
    {
        $this->fake->recommendedCourierId = '44';
        $fulfilment = $this->invoicedFulfilment('RDE950005', 'DELHI-RETAIL');
        $this->confirmAndShip($fulfilment);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame(1, $this->fake->labels);
    }

    public function test_ship_and_label_resumes_at_label_when_awb_exists(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE950006', 'DELHI-RETAIL');
        $this->prepareCourier($fulfilment);
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.confirm-recommended-courier.store', $fulfilment->fresh()))
            ->assertRedirect();
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment->fresh()));
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.awb.store', $fulfilment->fresh()));

        $this->assertNull(Shipment::query()->firstOrFail()->label_url);
        $this->assertSame(0, $this->fake->labels);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->assertNotNull(Shipment::query()->firstOrFail()->label_url);
        $this->assertSame(1, $this->fake->labels);
        $this->assertSame(1, $this->fake->creates);
    }

    public function test_label_failure_returns_partial_success_message(): void
    {
        $this->fake->recommendedCourierId = '44';
        $this->fake->nextLabelMode = 'timeout';
        $fulfilment = $this->invoicedFulfilment('RDE950007', 'DELHI-RETAIL');
        $this->confirmAndShip($fulfilment, shipOnly: true);

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Shipment created and AWB assigned. Label generation needs retry.');

        $this->assertNull(Shipment::query()->firstOrFail()->label_url);
        $this->assertNotNull(Shipment::query()->firstOrFail()->awb);
    }

    public function test_double_submit_does_not_duplicate_shipment_or_label(): void
    {
        $this->fake->recommendedCourierId = '44';
        $fulfilment = $this->invoicedFulfilment('RDE950008', 'DELHI-RETAIL');
        $this->confirmAndShip($fulfilment, shipOnly: true);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->labels);
    }

    public function test_ship_and_label_requires_invoice(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE950009', 'DELHI-RETAIL');
        $fulfilment->forceFill(['statutory_invoice_id' => null])->save();
        StatutoryInvoice::query()->whereKey($fulfilment->commerceOrder?->statutory_invoice_id)->delete();
        $fulfilment->commerceOrder?->forceFill(['statutory_invoice_id' => null])->save();
        $fulfilment->forceFill(['state' => HardwareFulfilmentState::SerialsAllocated])->save();

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->fake->creates);
    }

    public function test_ship_and_label_timeout_reconcile_does_not_duplicate_shipment(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE950011', 'DELHI-RETAIL');
        $this->confirmCourier($fulfilment);
        $this->fake->nextCreateMode = 'timeout_accepted';

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status');

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->searches);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertTrue(Shipment::query()->firstOrFail()->isBound());
    }

    public function test_ship_and_label_awb_recovery_preserves_manual_courier_and_single_shipment(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE950012', 'DELHI-RETAIL');
        $this->fake->courierOptions = [
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'prepaid_available' => true],
            ['courier_id' => '369', 'courier_name' => 'Shree Maruti 500 g Surface', 'prepaid_available' => true],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'prepaid_available' => true],
        ];
        $this->fake->recommendedCourierId = '369';
        $this->prepareCourier($fulfilment);
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier.store', $fulfilment->fresh()), [
                'courier_id' => '15084',
            ])
            ->assertRedirect();
        $this->fake->assignModeQueue = ['courier_not_serviceable', 'accepted'];

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect();

        $fresh = $fulfilment->fresh(['shipment']);
        $shipment = $fresh->shipment;
        $this->assertNotNull($shipment?->awb);
        $this->assertNotNull($shipment?->label_url);
        $this->assertSame(['15084', '369'], $this->fake->assignCourierIds);
        $this->assertSame('369', $fresh->selected_courier_id);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(2, $this->fake->awbs);
        $this->assertSame(1, $this->fake->labels);
        $this->assertSame('awb_recovery', $fresh->courier_options_snapshot['quoted_for'] ?? null);
    }

    public function test_ship_and_label_blocks_until_measured_parcel_is_attached_for_qty_two(): void
    {
        $fulfilment = $this->invoicedQtyFulfilment('RDE950013', 'DELHI-RETAIL', qty: 2, parcel: null);
        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $this->assertTrue($ready->canAttachMeasuredParcel);

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, $this->fake->courierLists);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment), [
                'length' => 40,
                'breadth' => 30,
                'height' => 20,
                'weight' => 2.5,
            ])
            ->assertRedirect();

        $this->confirmCourier($fulfilment->fresh());
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->assertSame(1, $this->fake->creates);
        $this->assertNotNull(Shipment::query()->firstOrFail()->label_url);
    }

    public function test_orchestration_refreshes_stale_courier_quote_before_ship_and_label(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE950014', 'DELHI-RETAIL');
        $this->confirmCourier($fulfilment);
        $listsAfterConfirm = $this->fake->courierLists;

        $fulfilment->commerceOrder?->forceFill([
            'parcel' => [
                'weight' => 0.9,
                'length' => 20,
                'breadth' => 15,
                'height' => 10,
            ],
        ])->save();

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('courier_id');

        $this->assertGreaterThan($listsAfterConfirm, $this->fake->courierLists);
        $this->assertNull($fulfilment->fresh()->selected_courier_id);
        $this->assertSame(0, $this->fake->creates);
    }

    public function test_ship_and_label_does_not_mutate_serials_after_invoice(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE950015', 'DELHI-RETAIL');
        $this->stockAt('DELHI-RETAIL', ['SN-ALT-015']);
        $itemId = (int) $fulfilment->commerceOrder?->items->first()?->id;

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), [
                'serials' => [$itemId => ['SN-ALT-015']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('serials');

        $this->assertSame(1, HardwareFulfilmentSerial::query()->where('hardware_fulfilment_id', $fulfilment->id)->count());
        $this->assertSame('SN-RDE950015-001', $fulfilment->fresh()->serials->first()?->serial_number);

        $this->confirmCourier($fulfilment);
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->assertSame('SN-RDE950015-001', $fulfilment->fresh()->serials->first()?->serial_number);
        $this->assertSame(1, HardwareFulfilmentSerial::query()->where('hardware_fulfilment_id', $fulfilment->id)->count());
    }

    public function test_orchestration_prepare_makes_ship_and_label_available_with_auto_select(): void
    {
        config(['shipping.auto_select_recommended_courier' => true]);
        $this->fake->recommendedCourierId = '44';
        $fulfilment = $this->invoicedFulfilment('RDE950010', 'DELHI-RETAIL');

        app(HardwareShipmentOrchestrationService::class)->preparePrerequisites($fulfilment, $this->admin);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $this->assertTrue($ready->canShipAndGenerateLabel);
        $this->assertNotNull($fulfilment->fresh()->selected_courier_id);
    }

    private function confirmAndShip(HardwareFulfilment $fulfilment, bool $shipOnly = false): void
    {
        $this->confirmCourier($fulfilment);
        if (! $shipOnly) {
            $this->actingAs($this->admin)
                ->post(route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment->fresh()))
                ->assertRedirect();
        }
    }

    private function confirmCourier(HardwareFulfilment $fulfilment): void
    {
        $this->prepareCourier($fulfilment);
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.confirm-recommended-courier.store', $fulfilment->fresh()))
            ->assertRedirect();
    }

    private function prepareCourier(HardwareFulfilment $fulfilment): void
    {
        $this->fake->recommendedCourierId = '44';
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect();
    }

    private function invoicedFulfilment(string $sourceId, string $branchCode): HardwareFulfilment
    {
        return $this->invoicedQtyFulfilment($sourceId, $branchCode);
    }

    private function invoicedQtyFulfilment(
        string $sourceId,
        string $branchCode,
        int $qty = 1,
        ?array $parcel = [
            'weight' => 0.4,
            'length' => 20,
            'breadth' => 15,
            'height' => 10,
        ],
    ): HardwareFulfilment {
        $fulfilment = $this->allocatedQtyFulfilment($sourceId, $branchCode, $qty, $parcel);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        return $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
    }

    private function allocatedFulfilment(string $sourceId, string $branchCode): HardwareFulfilment
    {
        return $this->allocatedQtyFulfilment($sourceId, $branchCode);
    }

    private function allocatedQtyFulfilment(
        string $sourceId,
        string $branchCode,
        int $qty = 1,
        ?array $parcel = [
            'weight' => 0.4,
            'length' => 20,
            'breadth' => 15,
            'height' => 10,
        ],
    ): HardwareFulfilment {
        $fulfilment = $this->readyQtyFulfilment($sourceId, $qty, $parcel);
        $serials = [];
        for ($i = 1; $i <= $qty; $i++) {
            $serials[] = sprintf('SN-%s-%03d', $sourceId, $i);
        }
        $this->stockAt($branchCode, $serials);
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment,
            $serials,
            $this->admin,
        );

        return $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
    }

    private function readyFulfilment(string $sourceId): HardwareFulfilment
    {
        return $this->readyQtyFulfilment($sourceId);
    }

    private function readyQtyFulfilment(
        string $sourceId,
        int $qty = 1,
        ?array $parcel = [
            'weight' => 0.4,
            'length' => 20,
            'breadth' => 15,
            'height' => 10,
        ],
    ): HardwareFulfilment {
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => 946,
            ],
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MFS110',
                'channel_sku' => 'RBMFS110L1',
                'notes' => 'Test-only orchestration map.',
            ],
        );

        $fulfilment = $this->ingestHardware($sourceId, qty: $qty, parcel: $parcel);
        app(HardwareFulfilmentWorkflowService::class)->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): void
    {
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branch($branchCode),
            $serials,
            $this->admin,
        );
    }

    /**
     * @param  list<string>  $branchCodes
     */
    private function userWithRole(string $role, array $branchCodes): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        foreach ($branchCodes as $code) {
            InventoryUserBranch::query()->firstOrCreate([
                'user_id' => $user->id,
                'branch_id' => $this->branch($code)->id,
            ]);
        }

        return $user;
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }

    private function ingestHardware(
        string $sourceId,
        int $qty = 1,
        ?array $parcel = [
            'weight' => 0.4,
            'length' => 20,
            'breadth' => 15,
            'height' => 10,
        ],
    ): HardwareFulfilment {
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
            'place_of_supply_state' => 'Delhi',
            'shipping_address' => [
                'line1' => '12 Shipping Street',
                'city' => 'Indore',
                'state' => 'Delhi',
                'pincode' => '452001',
                'country' => 'India',
            ],
            'parcel' => $parcel,
            'lines' => [[
                'description' => 'MFS110',
                'sku' => 'RBMFS110L1',
                'qty' => $qty,
                'unit_price' => 2549,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2160.17,
                'tax_total' => 388.83,
                'line_total' => 2549.00,
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
