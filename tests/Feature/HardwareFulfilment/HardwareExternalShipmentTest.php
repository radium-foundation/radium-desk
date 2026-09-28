<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentShippingMethod;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareExternalShipmentService;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentPackageEvidenceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareExternalShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private FakeShiprocketGateway $fake;

    private HardwareExternalShipmentService $external;

    private HardwareShipmentService $shipments;

    private User $actor;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        $this->seed(RolePermissionSeeder::class);
        Http::fake();
        Http::preventStrayRequests();
        $this->fake = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->fake);
        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'shipping.enabled' => true,
            'shipping.provider' => 'test',
            'shipping.pickup_locations.delhi' => 'TEST-DELHI-PICKUP',
            'shipping.pickup_postcodes.delhi' => '110019',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->external = app(HardwareExternalShipmentService::class);
        $this->shipments = app(HardwareShipmentService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-EXT-SHIP',
            'name' => 'Desk external ship test',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_external_method_selection_and_shipment_creation_without_shiprocket_calls(): void
    {
        $fulfilment = $this->invoicedFulfilment('RBP552', 'DELHI-RETAIL', pincode: '841437');

        $this->external->selectShippingMethod(
            $fulfilment,
            HardwareFulfilmentShippingMethod::External,
            $this->actor,
        );

        $shipment = $this->external->recordShipment(
            $fulfilment->fresh(),
            'trackon',
            null,
            'TRK-RBP552-001',
            'https://track.example.test/TRK-RBP552-001',
            'Gopalganj external dispatch',
            $this->actor,
        );

        $fulfilment = $fulfilment->fresh(['shipment']);
        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, $this->fake->awbs);
        $this->assertSame('external', $shipment->provider);
        $this->assertNull($shipment->external_order_id);
        $this->assertNull($shipment->external_shipment_id);
        $this->assertNull($shipment->courier_id);
        $this->assertSame('Trackon', $shipment->courier_name);
        $this->assertSame('TRK-RBP552-001', $shipment->awb);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->state);
        $this->assertSame(HardwareFulfilmentShippingMethod::External, $fulfilment->shipping_method);
        $this->assertTrue(
            ShipmentEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('source', 'external')
                ->whereIn('activity', ['external_awb_recorded', 'external_courier_selected'])
                ->exists(),
        );
    }

    public function test_rbp552_acceptance_flow_dispatches_after_package_photo(): void
    {
        $fulfilment = $this->invoicedFulfilment('RBP552', 'DELHI-RETAIL', pincode: '841437');

        $this->external->recordShipment(
            $fulfilment->fresh(),
            'trackon',
            null,
            'TRK-RBP552-ACCEPT',
            null,
            null,
            $this->actor,
        );

        $fulfilment = $fulfilment->fresh(['shipment']);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->state);
        $this->assertSame(0, $this->fake->creates);

        app(HardwareFulfilmentPackageEvidenceService::class)->attach(
            $fulfilment,
            HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel,
            UploadedFile::fake()->image('package.jpg'),
            $this->actor,
        );

        $updated = $this->external->dispatch($fulfilment->fresh(['shipment']), $this->actor);

        $this->assertSame(HardwareFulfilmentState::Shipped, $updated->state);
        $this->assertNotNull($updated->shipment?->dispatched_at);
        $this->assertTrue(
            ShipmentEvent::query()
                ->where('shipment_id', $updated->shipment_id)
                ->where('activity', 'external_dispatched')
                ->exists(),
        );
    }

    public function test_duplicate_awb_is_rejected(): void
    {
        $first = $this->invoicedFulfilment('RDE901001', 'DELHI-RETAIL');
        $second = $this->invoicedFulfilment('RDE901002', 'DELHI-RETAIL');

        $this->external->recordShipment($first, 'dtdc', null, 'DUP-AWB-100', null, null, $this->actor);

        $this->expectException(ValidationException::class);
        $this->external->recordShipment($second, 'delhivery', null, 'DUP-AWB-100', null, null, $this->actor);
    }

    public function test_other_courier_requires_display_name(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE901003', 'DELHI-RETAIL');

        $this->expectException(ValidationException::class);
        $this->external->recordShipment($fulfilment, 'other', null, 'OTH-100', null, null, $this->actor);
    }

    public function test_tracking_url_must_be_https(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE901004', 'DELHI-RETAIL');

        $this->expectException(ValidationException::class);
        $this->external->recordShipment($fulfilment, 'trackon', null, 'TRK-HTTPS', 'http://insecure.test', null, $this->actor);
    }

    public function test_external_label_upload_and_authenticated_download(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE901005', 'DELHI-RETAIL');
        $this->external->recordShipment($fulfilment, 'blue_dart', null, 'BD-100', null, null, $this->actor);

        $file = UploadedFile::fake()->create('label.pdf', 100, 'application/pdf');
        $this->external->uploadLabel($fulfilment->fresh(['shipment']), $file, $this->actor);

        $response = $this->actingAs($this->actor)
            ->get(route('inventory.hardware-fulfilments.external-label.download', $fulfilment));

        $response->assertOk();
        $this->assertTrue(
            ShipmentEvent::query()
                ->where('activity', 'external_label_uploaded')
                ->exists(),
        );
    }

    public function test_shiprocket_create_is_blocked_for_external_fulfilment(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE901006', 'DELHI-RETAIL');
        $this->external->selectShippingMethod($fulfilment, HardwareFulfilmentShippingMethod::External, $this->actor);

        $this->expectException(ValidationException::class);
        $this->shipments->createShipment($fulfilment->fresh());
    }

    public function test_external_http_routes_require_operate_permission(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE901007', 'DELHI-RETAIL');
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider)
            ->post(route('inventory.hardware-fulfilments.external-shipment.store', $fulfilment), [
                'courier_code' => 'trackon',
                'awb' => 'TRK-HTTP-1',
            ])
            ->assertForbidden();
    }

    private function invoicedFulfilment(
        string $sourceId,
        string $branchCode,
        string $placeOfSupply = 'Bihar',
        string $pincode = '452001',
    ): HardwareFulfilment {
        $fulfilment = $this->allocatedFulfilment($sourceId, $branchCode, $placeOfSupply, $pincode);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        return $fulfilment->fresh(['commerceOrder.items', 'serials.inventorySerial.branch']) ?? $fulfilment;
    }

    private function allocatedFulfilment(
        string $sourceId,
        string $branchCode,
        string $placeOfSupply = 'Bihar',
        string $pincode = '452001',
    ): HardwareFulfilment {
        $fulfilment = $this->readyFulfilment($sourceId, $branchCode, $placeOfSupply, $pincode);
        $serial = sprintf('SN-%s-001', $sourceId);
        $this->stockAt($branchCode, [$serial]);
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment,
            [$serial],
            $this->actor,
        );

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function readyFulfilment(
        string $sourceId,
        string $branchCode,
        string $placeOfSupply,
        string $pincode,
    ): HardwareFulfilment {
        $this->mapModel(951);
        $fulfilment = $this->ingestHardware($sourceId, $placeOfSupply, $pincode);
        $this->assignBranch($fulfilment, $branchCode);
        app(HardwareFulfilmentWorkflowService::class)->transition(
            $fulfilment,
            HardwareFulfilmentState::ReadyForFulfilment,
        );

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function mapModel(int $modelId): void
    {
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
            ],
            ['inventory_product_id' => $this->product->id],
        );
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): void
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $branchCode],
            ['name' => $branchCode, 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        app(InventoryStockService::class)->stockInSerialized($this->product, $branch, $serials, $this->actor);
    }

    private function assignBranch(HardwareFulfilment $fulfilment, string $code): InventoryBranch
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();

        return $branch;
    }

    private function ingestHardware(string $sourceId, string $placeOfSupply, string $pincode): HardwareFulfilment
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
            'place_of_supply_state' => $placeOfSupply,
            'shipping_address' => [
                'line1' => '12 Shipping Street',
                'city' => 'Gopalganj',
                'state' => $placeOfSupply,
                'pincode' => $pincode,
                'country' => 'India',
            ],
            'parcel' => [
                'weight' => 0.24,
                'length' => 14,
                'breadth' => 9,
                'height' => 7,
            ],
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049.00,
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
