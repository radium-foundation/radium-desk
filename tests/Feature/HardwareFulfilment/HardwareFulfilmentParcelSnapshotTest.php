<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryProductPackaging;
use App\Models\InventoryUserBranch;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentParcelSnapshotService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentParcelSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentWorkflowService $workflow;

    private FakeShiprocketGateway $fake;

    private User $operator;

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
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.callback.enabled' => false,
            'hardware_fulfilment.sku_map' => [],
            'shipping.enabled' => false,
            'shipping.provider' => 'none',
            'shipping.http_enabled' => false,
            'shipping.pickup_locations.delhi' => 'RADDELHI',
            'shipping.pickup_locations.mumbai' => 'RADIUMUM',
            'shipping.channel_id' => '',
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
        $this->operator = $this->userWithRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM, ['DELHI-RETAIL', 'MUMBAI']);
        $this->admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_verified_qty_one_packaging_attaches_and_inspect_uses_snapshot(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE910001', parcel: null);
        $this->verifyPack();
        $events = HardwareFulfilmentEvent::query()->count();
        $orderParcel = $fulfilment->commerceOrder?->parcel;

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.parcel-snapshot.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Parcel snapshot attached.');

        $fresh = $fulfilment->fresh();
        $this->assertSame($orderParcel, $fresh->commerceOrder?->parcel);
        $this->assertSame(0.24, (float) $fresh->parcel_snapshot['weight']);
        $this->assertSame(14.0, (float) $fresh->parcel_snapshot['length']);
        $this->assertSame(9.0, (float) $fresh->parcel_snapshot['breadth']);
        $this->assertSame(7.0, (float) $fresh->parcel_snapshot['height']);
        $this->assertSame('inventory_product_packaging', $fresh->parcel_snapshot['source']);
        $this->assertSame($this->product->id, $fresh->parcel_snapshot['inventory_product_id']);
        $this->assertSame($events, HardwareFulfilmentEvent::query()->count());
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fresh->state);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fresh);
        $this->assertSame('snapshot', $ready->parcelSource);
        $this->assertSame('0.24 kg · 14×9×7 cm', $ready->parcel);
        $this->assertFalse($ready->canAttachSnapshot);
        $this->assertStringContainsString('0.24 kg', (string) $ready->catalogPackaging);
        $this->assertTrue($ready->catalogVerified);
        Http::assertNothingSent();
    }

    public function test_attachment_is_idempotent_and_inspect_does_not_write(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE910002', parcel: null);
        $this->verifyPack();

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Attach verified packaging')
            ->assertSee('Not attached')
            ->assertSee('0.24 kg');

        $this->assertNull($fulfilment->fresh()->parcel_snapshot);

        app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment, $this->operator);
        $first = $fulfilment->fresh()->parcel_snapshot;
        app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment->fresh(), $this->operator);

        $this->assertSame($first, $fulfilment->fresh()->parcel_snapshot);
        $this->assertSame(1, HardwareFulfilment::query()->whereNotNull('parcel_snapshot')->count());
        Http::assertNothingSent();
    }

    public function test_catalog_edit_does_not_mutate_existing_snapshot_or_create_input(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE910003', parcel: null);
        $this->verifyPack();
        app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment, $this->operator);

        InventoryProductPackaging::query()->where('inventory_product_id', $this->product->id)->update([
            'gross_weight' => 0.5,
            'length' => 10,
            'breadth' => 10,
            'height' => 10,
        ]);

        $again = app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment->fresh(), $this->operator);
        $this->assertSame(0.24, (float) $again['weight']);
        $this->assertSame(14.0, (float) $again['length']);

        $this->enableFakeShipping();
        $ready = $this->selectTestCourier($fulfilment->fresh(), $this->operator);
        $shipment = app(HardwareShipmentService::class)->createShipment($ready, $this->operator);
        $this->assertSame(0.24, (float) $shipment->create_snapshot['parcel']['weight']);
        $this->assertSame('snapshot', $shipment->create_snapshot['parcel_source']);
        $this->assertSame(1, $this->fake->creates);
        Http::assertNothingSent();
    }

    public function test_missing_packaging_fails_closed(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE910004', parcel: null);

        try {
            app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment, $this->operator);
            $this->fail('Missing packaging must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Verified catalog packaging is missing', implode(' ', $exception->errors()['parcel'] ?? []));
        }

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $this->assertSame('unavailable', $ready->parcelSource);
        $this->assertContains('Parcel packaging not attached', $ready->blockers);
        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
    }

    public function test_qty_greater_than_one_fails_closed(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE910005', parcel: null, qty: 2);
        $this->verifyPack();

        try {
            app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment, $this->operator);
            $this->fail('qty>1 must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('quantity 1', implode(' ', $exception->errors()['parcel'] ?? []));
        }

        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
    }

    public function test_multi_sku_fails_closed(): void
    {
        $second = InventoryProduct::query()->create([
            'sku' => 'RBIMSOE3L1',
            'name' => 'Morpho e3',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $this->product->id,
            'catalog_sku' => 'MFS110',
            'channel_sku' => 'RBMFS110L1',
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 951,
            'inventory_product_id' => $second->id,
            'catalog_sku' => 'MSO1300',
            'channel_sku' => 'RBIMSOE3L1',
        ]);
        $this->verifyPack();
        $this->verifyPack($second);

        $fulfilment = $this->ingestHardware('RDE910006', [
            'parcel' => null,
            'lines' => [
                $this->physicalLine(946, 'RBMFS110L1', 'MFS110', 1),
                $this->physicalLine(951, 'RBIMSOE3L1', 'Morpho e3', 1),
            ],
        ]);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->stockAt('DELHI-RETAIL', ['SN-RDE910006-A'], $this->product);
        $this->stockAt('DELHI-RETAIL', ['SN-RDE910006-B'], $second);
        $items = $fulfilment->fresh('commerceOrder.items')->commerceOrder->items->values();
        app(HardwareSerialAllocationService::class)->allocate($fulfilment, [
            (int) $items[0]->id => ['SN-RDE910006-A'],
            (int) $items[1]->id => ['SN-RDE910006-B'],
        ], $this->operator);

        try {
            app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment->fresh(), $this->operator);
            $this->fail('Multi-SKU must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Multi-SKU', implode(' ', $exception->errors()['parcel'] ?? []));
        }

        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
    }

    public function test_ingest_parcel_takes_precedence_and_is_not_overwritten(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE910007');
        $this->verifyPack();
        $original = $fulfilment->commerceOrder?->parcel;

        try {
            app(HardwareFulfilmentParcelSnapshotService::class)->attachFromCatalog($fulfilment, $this->operator);
            $this->fail('Complete ingest parcel must block catalog attach.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ingest parcel', implode(' ', $exception->errors()['parcel'] ?? []));
        }

        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
        $this->assertSame($original, $fulfilment->fresh()->commerceOrder?->parcel);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $this->assertSame('ingest', $ready->parcelSource);
        $this->assertSame('0.4 kg · 20×15×10 cm', $ready->parcel);
    }

    public function test_operator_cannot_supply_parcel_on_attach_or_create(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE910008', parcel: null);
        $this->verifyPack();

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.parcel-snapshot.store', $fulfilment), [
                'weight' => 9.9,
                'country' => 'India',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors(['weight', 'country']);

        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
        $this->assertSame(0, Shipment::query()->count());
        Http::assertNothingSent();
    }

    public function test_qty_one_catalog_snapshot_does_not_require_measured_entry(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE910009', parcel: null);
        $this->verifyPack();

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Attach verified packaging')
            ->assertDontSee('Package Dimensions — Complete Packed Shipment')
            ->assertDontSee('Enter Package Dimensions');

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment), [
                'length' => 40,
                'breadth' => 30,
                'height' => 20,
                'weight' => 2.5,
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('parcel');

        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
        Http::assertNothingSent();
    }

    public function test_qty_greater_than_one_accepts_measured_parcel_without_touching_masters(): void
    {
        $other = $this->allocatedFulfilment('RDE910010', parcel: null, qty: 2);
        $fulfilment = $this->invoicedFulfilment('RDE910011', parcel: null, qty: 2);
        $pack = $this->verifyPack();
        $packUpdated = $pack->updated_at?->toDateTimeString();
        $commerceParcel = $fulfilment->commerceOrder?->parcel;

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Package Dimensions — Complete Packed Shipment')
            ->assertSee('Enter the dimensions and weight of the complete packed shipment')
            ->assertDontSee('Attach verified packaging');

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment), [
                'length' => 40,
                'breadth' => 30,
                'height' => 20,
                'weight' => 2.5,
                'save_for_future' => '1',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Packed shipment dimensions saved.');

        $fresh = $fulfilment->fresh();
        $this->assertSame($commerceParcel, $fresh->commerceOrder?->parcel);
        $this->assertNull($other->fresh()->parcel_snapshot);
        $this->assertSame((int) $fresh->id, (int) $fresh->parcel_snapshot['hardware_fulfilment_id']);
        $this->assertSame(2, (int) $fresh->parcel_snapshot['quantity']);
        $this->assertSame(2.5, (float) $fresh->parcel_snapshot['weight']);
        $this->assertSame(40.0, (float) $fresh->parcel_snapshot['length']);
        $this->assertSame(30.0, (float) $fresh->parcel_snapshot['breadth']);
        $this->assertSame(20.0, (float) $fresh->parcel_snapshot['height']);
        $this->assertSame(4.8, (float) $fresh->parcel_snapshot['volumetric_weight']);
        $this->assertSame('measured_shipment_package', $fresh->parcel_snapshot['source']);
        $this->assertTrue((bool) $fresh->parcel_snapshot['save_for_future_requested']);
        $this->assertSame($this->operator->id, $fresh->parcel_snapshot['snapshotted_by_user_id']);
        $this->assertSame(0.240, (float) $pack->fresh()->gross_weight);
        $this->assertSame(14.0, (float) $pack->fresh()->length);
        $this->assertSame($packUpdated, $pack->fresh()->updated_at?->toDateTimeString());
        $this->assertSame(1, InventoryProductPackaging::query()->count());

        $ready = app(HardwareShipmentEligibility::class)->inspect($fresh);
        $this->assertSame('measured', $ready->parcelSource);
        $this->assertFalse($ready->canAttachMeasuredParcel);
        $this->assertSame('2.50 kg', $ready->actualWeight);
        $this->assertSame('4.80 kg', $ready->volumetricWeight);
        Http::assertNothingSent();
    }

    public function test_qty_greater_than_one_rejects_invalid_measured_dimensions_and_weight(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE910012', parcel: null, qty: 2);
        $this->verifyPack();

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment), [
                'length' => 0.5,
                'breadth' => 30,
                'height' => 20,
                'weight' => 2.5,
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('length');

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment), [
                'length' => 40,
                'breadth' => 30,
                'height' => 20,
                'weight' => 0,
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('weight');

        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
        $this->assertSame(0.240, (float) InventoryProductPackaging::query()->firstOrFail()->gross_weight);
        Http::assertNothingSent();
    }

    public function test_qty_greater_than_one_create_uses_measured_parcel_and_is_then_immutable(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE910013', parcel: null, qty: 2);
        $this->verifyPack();
        $this->enableFakeShipping();

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('parcel');

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->fake->courierLists);
        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, Shipment::query()->count());

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment), [
                'length' => 40,
                'breadth' => 30,
                'height' => 20,
                'weight' => 2.5,
            ])
            ->assertSessionHas('status', 'Packed shipment dimensions saved.');

        $ready = $this->selectTestCourier($fulfilment->fresh(), $this->operator);
        $shipment = app(HardwareShipmentService::class)->createShipment($ready, $this->operator);

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(2.5, (float) $this->fake->lastCreateRequest?->weight);
        $this->assertSame(40.0, (float) $this->fake->lastCreateRequest?->length);
        $this->assertSame(30.0, (float) $this->fake->lastCreateRequest?->breadth);
        $this->assertSame(20.0, (float) $this->fake->lastCreateRequest?->height);
        $this->assertSame(2.5, (float) $shipment->create_snapshot['parcel']['weight']);
        $this->assertSame('measured', $shipment->create_snapshot['parcel_source']);
        $this->assertSame(2, (int) ($this->fake->lastCreateRequest->items[0]['units'] ?? 0));
        $this->assertSame('Prepaid', $this->fake->lastCreateRequest?->paymentMethod);
        $this->assertSame(0.240, (float) InventoryProductPackaging::query()->firstOrFail()->gross_weight);
        $this->assertNull($fulfilment->fresh()->commerceOrder?->parcel);

        $first = $fulfilment->fresh()->parcel_snapshot;
        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment->fresh()), [
                'length' => 99,
                'breadth' => 99,
                'height' => 99,
                'weight' => 9.9,
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('parcel');

        $this->assertSame($first, $fulfilment->fresh()->parcel_snapshot);

        app(HardwareShipmentService::class)->createShipment($fulfilment->fresh(), $this->operator);
        $this->assertSame(1, $this->fake->creates);
        Http::assertNothingSent();
    }

    private function enableFakeShipping(): void
    {
        $this->app->instance(ShiprocketGateway::class, $this->fake);
        config([
            'shipping.enabled' => true,
            'shipping.provider' => 'test',
            'shipping.http_enabled' => false,
            'shipping.pickup_postcodes.delhi' => '110001',
            'shipping.pickup_postcodes.mumbai' => '400001',
        ]);
    }

    private function verifyPack(?InventoryProduct $product = null): InventoryProductPackaging
    {
        return InventoryProductPackaging::query()->create([
            'inventory_product_id' => ($product ?? $this->product)->id,
            'gross_weight' => 0.240,
            'length' => 14,
            'breadth' => 9,
            'height' => 7,
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
            'verified_by_user_id' => $this->admin->id,
            'verified_at' => now(),
        ]);
    }

    private function invoicedFulfilment(string $sourceId, ?array $parcel = [
        'weight' => 0.4,
        'length' => 20,
        'breadth' => 15,
        'height' => 10,
    ], int $qty = 1): HardwareFulfilment
    {
        $fulfilment = $this->allocatedFulfilment($sourceId, parcel: $parcel, qty: $qty);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function allocatedFulfilment(string $sourceId, ?array $parcel = [
        'weight' => 0.4,
        'length' => 20,
        'breadth' => 15,
        'height' => 10,
    ], int $qty = 1): HardwareFulfilment
    {
        $fulfilment = $this->readyFulfilment($sourceId, $parcel, $qty);
        $serials = [];
        for ($i = 1; $i <= $qty; $i++) {
            $serials[] = sprintf('SN-%s-%03d', $sourceId, $i);
        }
        $this->stockAt('DELHI-RETAIL', $serials, $this->product);
        app(HardwareSerialAllocationService::class)->allocateSerials($fulfilment, $serials, $this->operator);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function readyFulfilment(string $sourceId, ?array $parcel, int $qty = 1): HardwareFulfilment
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
            ],
        );

        $fulfilment = $this->ingestHardware($sourceId, [
            'parcel' => $parcel,
            'lines' => [$this->physicalLine(946, 'RBMFS110L1', 'MFS110', $qty)],
        ]);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials, InventoryProduct $product): void
    {
        app(InventoryStockService::class)->stockInSerialized(
            $product,
            $this->branch($branchCode),
            $serials,
            $this->operator,
        );
    }

    /**
     * @param  list<string>  $branchCodes
     */
    private function userWithRole(string $role, array $branchCodes = ['DELHI-RETAIL', 'MUMBAI']): User
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

    /**
     * @return array<string, mixed>
     */
    private function physicalLine(int $modelId, string $sku, string $description, int $qty): array
    {
        return [
            'description' => $description,
            'sku' => $sku,
            'qty' => $qty,
            'unit_price' => 2549,
            'hsn_sac' => '84716050',
            'gst_percentage' => 18,
            'taxable_value' => 2160.17 * $qty,
            'tax_total' => 388.83 * $qty,
            'line_total' => 2549.00 * $qty,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => $modelId,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function ingestHardware(string $sourceId, array $overrides = []): HardwareFulfilment
    {
        $payload = array_merge([
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
            'parcel' => [
                'weight' => 0.4,
                'length' => 20,
                'breadth' => 15,
                'height' => 10,
            ],
            'lines' => [$this->physicalLine(946, 'RBMFS110L1', 'MFS110', 1)],
        ], $overrides);

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
