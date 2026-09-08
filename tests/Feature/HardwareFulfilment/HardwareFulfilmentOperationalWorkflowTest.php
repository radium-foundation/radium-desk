<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentOperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

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
        $this->operator = $this->userWithRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM, ['DELHI-RETAIL', 'MUMBAI']);
        $this->admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN, ['DELHI-RETAIL', 'MUMBAI']);
    }

    public function test_show_and_inspect_do_not_mutate_or_show_unready_actions(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE940001', 'DELHI-RETAIL');
        $updated = $fulfilment->updated_at?->toDateTimeString();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Shipping readiness')
            ->assertSee('Courier')
            ->assertSee('Get Courier Options')
            ->assertDontSee('Assign AWB')
            ->assertDontSee('Generate Shipping Label')
            ->assertDontSee('Request Pickup')
            ->assertDontSee('Generate Manifest')
            ->assertDontSee('Mark Ready for Pickup')
            ->assertDontSee('Record labelled package');

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $this->assertFalse($ready->canCreate);
        $this->assertFalse($ready->canAssignAwb);
        $this->assertFalse($ready->canGenerateLabel);
        $this->assertNull($fulfilment->fresh()->parcel_snapshot);
        $this->assertNull($fulfilment->fresh()->shipment_id);
        $this->assertSame($updated, $fulfilment->fresh()->updated_at?->toDateTimeString());
        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, $this->fake->awbs);
        $this->assertSame(0, $this->fake->labels);
        Http::assertNothingSent();
    }

    public function test_label_pickup_and_manifest_are_gated_until_awb_and_pickup(): void
    {
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE940002', 'DELHI-RETAIL'), $this->admin);

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.awb.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.manifest.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Shipping label generated.');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment->fresh()))
            ->assertRedirect();

        $shipment = Shipment::query()->firstOrFail();
        $this->assertNotNull($shipment->label_url);
        $this->assertSame(1, $this->fake->labels);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Pickup requested.');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->assertNotNull($shipment->fresh()->pickup_requested_at);
        $this->assertSame(1, $this->fake->pickups);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.manifest.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Manifest generated.');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.manifest.store', $fulfilment->fresh()))
            ->assertRedirect();

        $freshShipment = $shipment->fresh();
        $this->assertNotNull($freshShipment->manifest_url);
        $this->assertNotNull($freshShipment->manifest_id);
        $this->assertSame(1, $this->fake->manifests);

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Download Manifest')
            ->assertSee($freshShipment->manifest_url, false)
            ->assertDontSee('Print Manifest');

        Http::assertNothingSent();
    }

    public function test_package_evidence_and_ready_for_pickup_gates(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940003');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment), [
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied->value,
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('photo');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment), [
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel->value,
                'photo' => UploadedFile::fake()->image('before.jpg', 200, 200),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Package photo recorded.');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.ready-for-pickup.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment->fresh()), [
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied->value,
                'photo' => UploadedFile::fake()->image('labelled.jpg', 200, 200),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Label-applied package photo recorded.');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->post(route('inventory.hardware-fulfilments.ready-for-pickup.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $this->assertNull($fulfilment->fresh()->ready_for_pickup_at);
        $this->assertSame(0, $this->fake->pickups);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Pickup requested.');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ready-for-pickup.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Fulfilment marked ready for pickup.');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.ready-for-pickup.store', $fulfilment->fresh()))
            ->assertRedirect();

        $fresh = $fulfilment->fresh();
        $this->assertNotNull($fresh->ready_for_pickup_at);
        $this->assertNotNull($fresh->shipment?->pickup_requested_at);
        $this->assertSame(1, $this->fake->pickups);
        $this->assertSame(2, HardwareFulfilmentPackageEvidence::query()->count());
        $this->assertTrue(
            HardwareFulfilmentEvent::query()
                ->where('hardware_fulfilment_id', $fresh->id)
                ->where('payload->reason', 'hardware_ready_for_pickup')
                ->exists()
        );

        $evidence = HardwareFulfilmentPackageEvidence::query()
            ->where('kind', HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied)
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.package-evidence.show', [$fresh, $evidence]))
            ->assertOk();

        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fresh->state);
        Http::assertNothingSent();
    }

    public function test_request_pickup_is_available_only_when_awb_is_assigned(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940007');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('id="hardware-pickup-submit"', false)
            ->assertSee('Not requested')
            ->assertSee('Manifest not generated')
            ->assertDontSee('id="hardware-pickup-requested-status"', false);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $this->assertTrue($ready->canRequestPickup);
        $this->assertSame('Not requested', $ready->pickupStatus);
        $this->assertFalse($ready->canMarkReadyForPickup);
        $this->assertSame(0, $this->fake->pickups);
        Http::assertNothingSent();
    }

    public function test_successful_pickup_persists_and_removes_the_request_action(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940008');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment))
            ->assertRedirect()
            ->assertSessionHas('status', 'Pickup requested.');

        $shipment = Shipment::query()->firstOrFail();
        $this->assertNotNull($shipment->pickup_requested_at);
        $this->assertSame(1, $this->fake->pickups);
        $this->assertTrue(
            HardwareFulfilmentEvent::query()
                ->where('hardware_fulfilment_id', $fulfilment->id)
                ->where('payload->reason', 'hardware_pickup_requested')
                ->exists()
        );

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Pickup Requested')
            ->assertSee('id="hardware-pickup-requested-status"', false)
            ->assertDontSee('id="hardware-pickup-submit"', false);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $this->assertFalse($ready->canRequestPickup);
        $this->assertSame('Requested', $ready->pickupStatus);
        $this->assertNotNull($ready->pickupRequestedAt);
        Http::assertNothingSent();
    }

    public function test_repeated_pickup_request_does_not_call_the_provider_again(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940009');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment))
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Pickup requested.');

        $this->assertSame(1, $this->fake->pickups);
        $this->assertSame(
            1,
            HardwareFulfilmentEvent::query()
                ->where('hardware_fulfilment_id', $fulfilment->id)
                ->where('payload->reason', 'hardware_pickup_requested')
                ->count()
        );
        Http::assertNothingSent();
    }

    public function test_already_in_pickup_queue_reconciles_local_state_without_a_second_provider_call(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940010');
        $this->fake->nextPickupMode = 'already_queued';

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment))
            ->assertRedirect()
            ->assertSessionHas('status', 'Pickup already queued at the provider. Local pickup state reconciled.');

        $shipment = Shipment::query()->firstOrFail();
        $this->assertNotNull($shipment->pickup_requested_at);
        $this->assertSame(1, $this->fake->pickups);
        $this->assertTrue(
            HardwareFulfilmentEvent::query()
                ->where('hardware_fulfilment_id', $fulfilment->id)
                ->where('payload->reason', 'hardware_pickup_reconciled_already_queued')
                ->exists()
        );

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Pickup requested.');

        $this->assertSame(1, $this->fake->pickups);
        $this->assertSame(
            1,
            HardwareFulfilmentEvent::query()
                ->where('payload->reason', 'hardware_pickup_reconciled_already_queued')
                ->count()
        );

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Pickup Requested')
            ->assertDontSee('id="hardware-pickup-submit"', false);

        Http::assertNothingSent();
    }

    public function test_already_in_pickup_queue_rejected_wrapper_reconciles_without_flashing_the_400(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940011');
        $this->fake->nextPickupMode = 'already_queued_rejected';

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Pickup already queued at the provider. Local pickup state reconciled.')
            ->assertSessionDoesntHaveErrors();

        $this->assertNotNull(Shipment::query()->firstOrFail()->pickup_requested_at);
        $this->assertSame(1, $this->fake->pickups);
        $this->assertTrue(
            HardwareFulfilmentEvent::query()
                ->where('payload->reason', 'hardware_pickup_reconciled_already_queued')
                ->exists()
        );

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Pickup Requested')
            ->assertDontSee('HTTP 400')
            ->assertDontSee('Already in Pickup Queue')
            ->assertDontSee('id="hardware-pickup-submit"', false);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, $this->fake->pickups);
        Http::assertNothingSent();
    }

    public function test_label_applied_photo_is_rejected_before_awb(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE940004', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment), [
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied->value,
                'photo' => UploadedFile::fake()->image('early.jpg', 120, 120),
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, HardwareFulfilmentPackageEvidence::query()->count());
    }

    public function test_unauthorised_users_cannot_run_operational_actions(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940005');
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.manifest.store', $fulfilment))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment), [
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel->value,
                'photo' => UploadedFile::fake()->image('x.jpg', 80, 80),
            ])
            ->assertForbidden();

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment))
            ->assertRedirect();

        $this->assertSame(1, $this->fake->labels);
    }

    public function test_ambiguous_label_timeout_does_not_invent_a_url(): void
    {
        $fulfilment = $this->awbFulfilment('RDE940006');
        $this->fake->nextLabelMode = 'timeout';

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $this->assertNull(Shipment::query()->firstOrFail()->label_url);
        $this->assertSame(1, $this->fake->labels);
    }

    private function awbFulfilment(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment($sourceId, 'DELHI-RETAIL'), $this->admin);
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment));
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.awb.store', $fulfilment->fresh()));

        return $fulfilment->fresh(['shipment', 'packageEvidences']) ?? $fulfilment;
    }

    private function invoicedFulfilment(string $sourceId, string $branchCode): HardwareFulfilment
    {
        $fulfilment = $this->allocatedFulfilment($sourceId, $branchCode);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function allocatedFulfilment(string $sourceId, string $branchCode): HardwareFulfilment
    {
        $fulfilment = $this->readyFulfilment($sourceId);
        $this->stockAt($branchCode, [sprintf('SN-%s-001', $sourceId)]);
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment,
            [sprintf('SN-%s-001', $sourceId)],
            $this->operator,
        );

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function readyFulfilment(string $sourceId): HardwareFulfilment
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
                'notes' => 'Test-only operational workflow map.',
            ],
        );

        $fulfilment = $this->ingestHardware($sourceId);
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
            $this->operator,
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

    private function ingestHardware(string $sourceId): HardwareFulfilment
    {
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
            'parcel' => [
                'weight' => 0.4,
                'length' => 20,
                'breadth' => 15,
                'height' => 10,
            ],
            'lines' => [[
                'description' => 'MFS110',
                'sku' => 'RBMFS110L1',
                'qty' => 1,
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
