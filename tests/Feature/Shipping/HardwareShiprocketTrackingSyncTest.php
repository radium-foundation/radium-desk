<?php

namespace Tests\Feature\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Events\Dashboard\HardwareFulfilmentsUpdated;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareShiprocketTrackingService;
use App\Services\Shipping\NullShiprocketGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareShiprocketTrackingSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeShiprocketGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->fake = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->fake);
        User::factory()->create(['is_active' => true])->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_changed_status_persists_once_and_publishes_update(): void
    {
        Event::fake([HardwareFulfilmentsUpdated::class]);
        $shipment = $this->awbShipment();

        $result = app(HardwareShiprocketTrackingService::class)->syncShipmentId((int) $shipment->id);

        $this->assertSame('changed', $result);
        $fresh = $shipment->fresh();
        $this->assertSame('in_transit', $fresh->provider_track_status);
        $this->assertSame('in_transit', $fresh->provider_track_normalized);
        $this->assertNotNull($fresh->provider_tracked_at);
        $this->assertSame(1, ShipmentEvent::query()->where('activity', 'track_synced')->count());
        Event::assertDispatched(HardwareFulfilmentsUpdated::class);
        $this->assertSame(1, $this->fake->tracks);
    }

    public function test_unchanged_status_is_idempotent(): void
    {
        Event::fake([HardwareFulfilmentsUpdated::class]);
        $shipment = $this->awbShipment([
            'provider_track_status' => 'in_transit',
            'provider_track_normalized' => 'in_transit',
            'provider_tracked_at' => now()->subHour(),
        ]);

        $result = app(HardwareShiprocketTrackingService::class)->syncShipmentId((int) $shipment->id);

        $this->assertSame('unchanged', $result);
        $this->assertSame(0, ShipmentEvent::query()->where('activity', 'track_synced')->count());
        Event::assertNotDispatched(HardwareFulfilmentsUpdated::class);
        $this->assertSame(1, $this->fake->tracks);
    }

    public function test_timeout_does_not_erase_verified_status(): void
    {
        $shipment = $this->awbShipment([
            'provider_track_status' => 'in_transit',
            'provider_track_normalized' => 'in_transit',
            'provider_tracked_at' => now()->subHour(),
        ]);
        $this->fake->nextTrackMode = 'timeout';

        $result = app(HardwareShiprocketTrackingService::class)->syncShipmentId((int) $shipment->id);

        $this->assertSame('skipped', $result);
        $fresh = $shipment->fresh();
        $this->assertSame('in_transit', $fresh->provider_track_status);
        $this->assertSame('in_transit', $fresh->provider_track_normalized);
    }

    public function test_empty_status_does_not_corrupt_state(): void
    {
        $shipment = $this->awbShipment([
            'provider_track_status' => 'in_transit',
            'provider_track_normalized' => 'in_transit',
        ]);
        $this->fake->nextTrackMode = 'empty';

        $result = app(HardwareShiprocketTrackingService::class)->syncShipmentId((int) $shipment->id);

        $this->assertSame('skipped', $result);
        $this->assertSame('in_transit', $shipment->fresh()->provider_track_status);
    }

    public function test_missing_awb_and_external_id_is_skipped(): void
    {
        $shipment = $this->awbShipment(['awb' => '', 'external_shipment_id' => '']);

        $result = app(HardwareShiprocketTrackingService::class)->syncShipmentId((int) $shipment->id);

        $this->assertSame('skipped', $result);
        $this->assertSame(0, $this->fake->tracks);
    }

    public function test_unmapped_status_is_persisted_without_inventing_stage(): void
    {
        Event::fake([HardwareFulfilmentsUpdated::class]);
        $shipment = $this->awbShipment();
        $this->fake->nextTrackMode = 'unknown';

        $result = app(HardwareShiprocketTrackingService::class)->syncShipmentId((int) $shipment->id);

        $this->assertSame('changed', $result);
        $fresh = $shipment->fresh();
        $this->assertSame('some_unmapped_status', $fresh->provider_track_status);
        $this->assertSame('unknown', $fresh->provider_track_normalized);
        Event::assertDispatched(HardwareFulfilmentsUpdated::class);
    }

    public function test_verified_out_for_pickup_status_is_normalized_and_broadcast(): void
    {
        Event::fake([HardwareFulfilmentsUpdated::class]);
        $shipment = $this->awbShipment();
        $this->fake->nextTrackMode = 'out_for_pickup';

        $result = app(HardwareShiprocketTrackingService::class)->syncShipmentId((int) $shipment->id);

        $this->assertSame('changed', $result);
        $fresh = $shipment->fresh();
        $this->assertSame('19', $fresh->provider_track_status);
        $this->assertSame('out_for_pickup', $fresh->provider_track_normalized);
        Event::assertDispatched(HardwareFulfilmentsUpdated::class);
        $this->assertSame(1, $this->fake->tracks);
    }

    public function test_tracking_command_is_disabled_by_default(): void
    {
        config(['shipping.tracking.sync_enabled' => false]);
        $this->awbShipment();

        $this->artisan('shipping:sync-shiprocket-tracking')
            ->expectsOutput('Shiprocket tracking sync disabled.')
            ->assertSuccessful();

        $this->assertSame(0, $this->fake->tracks);
        $this->assertDatabaseMissing('shipment_events', ['activity' => 'track_synced']);
    }

    public function test_null_gateway_scans_nothing(): void
    {
        $this->app->forgetInstance(ShiprocketGateway::class);
        $this->app->bind(ShiprocketGateway::class, NullShiprocketGateway::class);
        $this->awbShipment();

        $stats = app(HardwareShiprocketTrackingService::class)->syncEligible(25);

        $this->assertSame(['scanned' => 0, 'changed' => 0, 'skipped' => 0, 'failed' => 0], $stats);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function awbShipment(array $overrides = []): Shipment
    {
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-RDE902900',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE902900',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE902900',
            'payload_hash' => hash('sha256', 'RDE902900'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE902900',
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::AwbAssigned,
            'ingested_at' => now(),
        ]);

        $shipment = Shipment::query()->create(array_merge([
            'shipment_no' => 'HW-RDE902900',
            'commerce_order_id' => $commerce->id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => 'shiprocket',
            'status' => ShipmentStatus::AwbAssigned,
            'awb' => 'AWB902900',
            'external_order_id' => 'ext-RDE902900',
            'external_shipment_id' => 'shp-RDE902900',
            'idempotency_key' => 'ship:RDE902900',
            'correlation_id' => (string) Str::uuid(),
        ], $overrides));

        $fulfilment->forceFill([
            'shipment_id' => $shipment->id,
            'awb' => $shipment->awb,
        ])->save();

        return $shipment->fresh();
    }
}
