<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\OutboxEventStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareShipmentDocumentsService;
use App\Services\Shipping\Data\ShiprocketSearchResult;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackOutboxWriter;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Services\Inventory\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentP5ShipmentTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareShipmentService $shipments;

    private HardwareFulfilmentWorkflowService $workflow;

    private FakeShiprocketGateway $fake;

    private User $actor;

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
            'shipping.enabled' => true,
            'shipping.provider' => 'test',
            'shipping.pickup_locations.delhi' => 'TEST-DELHI-PICKUP',
            'shipping.pickup_locations.mumbai' => 'TEST-MUMBAI-PICKUP',
            'shipping.pickup_postcodes.delhi' => '110001',
            'shipping.pickup_postcodes.mumbai' => '400001',
            'shipping.channel_id' => '',
            'shipping.preferred_courier_ids' => [],
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->shipments = app(HardwareShipmentService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-MSO-SHIP',
            'name' => 'Desk MSO ship test',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_shipment_is_blocked_before_invoice(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE900501', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Shipment must wait for invoice.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('INVOICE_ISSUED', implode(' ', $exception->errors()['shipment'] ?? []));
        }

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->fresh()->state);
    }

    public function test_shipment_is_blocked_before_serial_allocation(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900502', 'DELHI-RETAIL');

        $this->expectException(ValidationException::class);
        $this->shipments->createShipment($fulfilment);
    }

    public function test_shipment_is_blocked_with_incomplete_serial_set(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900503', 'DELHI-RETAIL');
        HardwareFulfilmentSerial::query()->where('hardware_fulfilment_id', $fulfilment->id)->delete();

        try {
            $this->shipments->createShipment($fulfilment->fresh());
            $this->fail('Incomplete serials must block shipment.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('allocated serials', strtolower(implode(' ', $exception->errors()['serials'] ?? [])));
        }

        $this->assertSame(0, $this->fake->creates);
    }

    public function test_shipment_is_blocked_without_verified_payment(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900521', 'DELHI-RETAIL');
        $fulfilment->forceFill(['paid_recognized_at' => null])->save();
        $fulfilment->commerceOrder?->forceFill([
            'payment_status' => 'pending',
            'paid_at' => null,
        ])->save();

        try {
            $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items']));
            $this->fail('Unverified payment must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('verified payment', implode(' ', $exception->errors()['payment'] ?? []));
        }

        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_shipment_is_blocked_without_parcel_data(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900515', 'DELHI-RETAIL');
        $fulfilment->commerceOrder?->forceFill(['parcel' => null])->save();

        try {
            $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items']));
            $this->fail('Missing parcel must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('parcel', strtolower(implode(' ', $exception->errors()['parcel'] ?? [])));
        }

        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_shipment_is_blocked_without_shipping_address(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900504', 'DELHI-RETAIL');
        $fulfilment->commerceOrder?->forceFill(['shipping_address_structured' => null])->save();

        try {
            $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items']));
            $this->fail('Missing shipping address must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('structured shipping address', implode(' ', $exception->errors()['address'] ?? []));
        }

        $this->assertSame(0, $this->fake->creates);
    }

    public function test_shipment_is_blocked_without_fulfilment_location(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900505', 'DELHI-RETAIL');
        $fulfilment->forceFill(['fulfilment_branch_id' => null])->save();

        try {
            $this->shipments->createShipment($fulfilment->fresh());
            $this->fail('Missing branch must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('fulfilment branch', implode(' ', $exception->errors()['branch'] ?? []));
        }

        $this->assertSame(0, $this->fake->creates);
    }

    public function test_shipment_is_blocked_without_pickup_configuration(): void
    {
        config(['shipping.pickup_locations.delhi' => '']);
        $fulfilment = $this->invoicedFulfilment('RDE900506', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Missing pickup must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('pickup nickname', implode(' ', $exception->errors()['pickup'] ?? []));
        }

        $this->assertSame(0, $this->fake->creates);
    }

    public function test_shipment_is_blocked_with_unknown_branch(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900517', 'DELHI-RETAIL');
        $unknown = InventoryBranch::query()->create([
            'code' => 'PUNE',
            'name' => 'Pune',
            'is_active' => true,
        ]);
        $fulfilment->forceFill(['fulfilment_branch_id' => $unknown->id])->save();
        HardwareFulfilmentSerial::query()
            ->where('hardware_fulfilment_id', $fulfilment->id)
            ->get()
            ->each(function (HardwareFulfilmentSerial $row) use ($unknown): void {
                if ($row->inventory_serial_id !== null) {
                    InventorySerial::query()->whereKey($row->inventory_serial_id)->update([
                        'branch_id' => $unknown->id,
                    ]);
                }
            });

        try {
            $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items', 'serials.inventorySerial.branch']));
            $this->fail('Unknown branch must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('pickup mapping', implode(' ', $exception->errors()['pickup'] ?? []));
        }

        $this->assertSame(0, $this->fake->creates);
    }

    public function test_mixed_physical_branches_are_rejected(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900518', 'DELHI-RETAIL', qty: 2);
        $mumbai = InventoryBranch::query()->firstOrCreate(
            ['code' => 'MUMBAI'],
            ['name' => 'MUMBAI', 'is_active' => true],
        );
        $serial = HardwareFulfilmentSerial::query()
            ->where('hardware_fulfilment_id', $fulfilment->id)
            ->orderByDesc('id')
            ->firstOrFail();
        InventorySerial::query()->whereKey($serial->inventory_serial_id)->update([
            'branch_id' => $mumbai->id,
        ]);

        try {
            $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items', 'serials.inventorySerial.branch']));
            $this->fail('Mixed branches must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('multiple physical', implode(' ', $exception->errors()['branch'] ?? []));
        }

        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
    }

    public function test_delhi_and_mumbai_pickup_follow_stock_not_customer_state(): void
    {
        $delhi = $this->invoicedFulfilment('RDE900507', 'DELHI-RETAIL', placeOfSupply: 'Maharashtra');
        $mumbai = $this->invoicedFulfilment('RDE900508', 'MUMBAI', placeOfSupply: 'Delhi');

        $delhiShipment = $this->shipments->createShipment($delhi);
        $mumbaiShipment = $this->shipments->createShipment($mumbai);

        $this->assertSame('TEST-DELHI-PICKUP', $delhiShipment->pickup_location);
        $this->assertSame('TEST-MUMBAI-PICKUP', $mumbaiShipment->pickup_location);
        $this->assertSame('Maharashtra', $delhi->commerceOrder?->place_of_supply_state);
        $this->assertSame('Delhi', $mumbai->commerceOrder?->place_of_supply_state);
    }

    public function test_successful_create_reaches_shipment_created_and_is_idempotent(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900509', 'DELHI-RETAIL');
        $first = $this->shipments->createShipment($fulfilment);
        $second = $this->shipments->createShipment($fulfilment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
        $this->assertSame('HW-RDE900509', $first->shipment_no);
        $this->assertNotNull($first->external_order_id);
        $this->assertNotNull($first->external_shipment_id);
        $this->assertSame($first->external_shipment_id, $fulfilment->fresh()->provider_shipment_id);
        $this->assertSame(['SN-RDE900509-001'], $first->serial_numbers);
        $this->assertNotNull($first->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(
            0,
            OutboxEvent::query()
                ->where('event_type', HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE)
                ->where('status', '!=', OutboxEventStatus::Pending)
                ->count(),
        );
        Http::assertNothingSent();
    }

    public function test_awb_assignment_persists_and_cannot_be_overwritten(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900510', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $assigned = $this->shipments->assignAwb($fulfilment->fresh());
        $again = $this->shipments->assignAwb($fulfilment->fresh());

        $this->assertSame($assigned->awb, $again->awb);
        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->fresh()->state);
        $this->assertSame($assigned->awb, $fulfilment->fresh()->awb);
        $this->assertSame($assigned->awb, $fulfilment->fresh()->provider_awb);
        $this->assertSame('12', $this->fake->lastAssignCourierId);
        $this->assertNotNull($this->fake->lastCourierRequest?->providerOrderId);
    }

    public function test_awb_keeps_stored_courier_when_it_is_still_serviceable(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900610', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);

        $this->fake->recommendedCourierId = '44';
        $assigned = $this->shipments->assignAwb($fulfilment->fresh());

        $this->assertSame('12', $this->fake->lastAssignCourierId);
        $this->assertSame('12', $fulfilment->fresh()->selected_courier_id);
        $this->assertSame('12', $assigned->courier_id);
        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame(1, $this->fake->creates);
    }

    public function test_awb_uses_current_recommended_courier_when_stored_is_not_serviceable(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900611', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => '15084',
            'selected_courier_name' => 'Delhivery_Surface',
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => '15084',
            'courier_name' => 'Delhivery_Surface',
        ])->save();

        $this->fake->courierOptions = [
            ['courier_id' => '15137', 'courier_name' => 'Blue Dart Surface'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface'],
        ];
        $this->fake->recommendedCourierId = '15106';

        $assigned = $this->shipments->assignAwb($fulfilment->fresh(['shipment']));

        $this->assertSame('15106', $this->fake->lastAssignCourierId);
        $this->assertNotSame('15137', $this->fake->lastAssignCourierId);
        $this->assertNotNull($this->fake->lastCourierRequest?->providerOrderId);
        $this->assertSame(['15106'], $this->fake->assignCourierIds);
        $this->assertSame('15106', $fulfilment->fresh()->selected_courier_id);
        $this->assertSame('Blue Dart Advantage Surface', $fulfilment->fresh()->selected_courier_name);
        $this->assertSame('15106', $assigned->courier_id);
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_awb_http_400_courier_not_serviceable_does_not_retry_when_no_eligible_alternate(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900612', 'DELHI-RETAIL');
        $created = $this->shipments->createShipment($fulfilment);
        $this->fake->courierOptions = [
            ['courier_id' => '12', 'courier_name' => 'Fake Surface', 'mode' => '0'],
        ];
        $this->fake->recommendedCourierId = '12';
        $this->fake->assignModeQueue = ['courier_not_serviceable'];

        try {
            $this->shipments->assignAwb($fulfilment->fresh());
            $this->fail('Non-serviceable courier must fail closed.');
        } catch (ValidationException $exception) {
            $errors = array_merge(
                $exception->errors()['shipping'] ?? [],
                $exception->errors()['courier_id'] ?? [],
            );
            $this->assertTrue(
                str_contains(implode(' ', $errors), 'Given courier not serviceable')
                || str_contains(implode(' ', $errors), 'no currently serviceable alternate courier'),
            );
        }

        $shipment = $created->fresh();
        $this->assertNull($shipment?->awb);
        $this->assertSame('provider_rejected', $shipment?->failure_class);
        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
        $this->assertSame($created->external_shipment_id, $shipment?->external_shipment_id);
    }

    public function test_awb_recovers_when_recommended_courier_was_already_rejected(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900630', 'DELHI-RETAIL');
        $created = $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => '15084',
            'selected_courier_name' => 'Delhivery_Surface',
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => '15084',
            'courier_name' => 'Delhivery_Surface',
        ])->save();

        $this->fake->courierOptions = [
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0', 'provider_recommended' => true],
            ['courier_id' => '10', 'courier_name' => 'Delhivery Air', 'mode' => '1'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
            ['courier_id' => '48', 'courier_name' => 'Ekart Logistics Air', 'mode' => '1'],
        ];
        $this->fake->recommendedCourierId = '15084';
        $this->fake->assignModeQueue = ['courier_not_serviceable', 'accepted'];

        $assigned = $this->shipments->assignAwb($fulfilment->fresh(['shipment']));

        $this->assertNotNull($assigned->awb);
        $this->assertSame(['15084', '15106'], $this->fake->assignCourierIds);
        $this->assertSame('15106', $assigned->courier_id);
        $this->assertSame('15106', $fulfilment->fresh()->selected_courier_id);
        $this->assertSame(2, $this->fake->awbs);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame($created->external_shipment_id, $assigned->external_shipment_id);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->fresh()->state);
    }

    public function test_awb_recovery_prefers_recommended_courier_after_courier_not_serviceable(): void
    {
        config(['shipping.preferred_courier_ids' => ['15084']]);
        $fulfilment = $this->invoicedFulfilment('RDE900631', 'DELHI-RETAIL');
        $created = $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => '15084',
            'selected_courier_name' => 'Delhivery_Surface',
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => '15084',
            'courier_name' => 'Delhivery_Surface',
        ])->save();

        $this->fake->courierOptions = [
            ['courier_id' => '48', 'courier_name' => 'Ekart Logistics Air', 'mode' => '1', 'provider_recommended' => true],
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
        ];
        $this->fake->recommendedCourierId = '48';
        $this->fake->assignModeQueue = ['courier_not_serviceable', 'accepted'];

        $assigned = $this->shipments->assignAwb($fulfilment->fresh(['shipment']));

        $this->assertNotNull($assigned->awb);
        $this->assertSame(['15084', '48'], $this->fake->assignCourierIds);
        $this->assertSame('48', $assigned->courier_id);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame($created->external_shipment_id, $assigned->external_shipment_id);
    }

    public function test_awb_recovery_retries_with_recommended_courier_when_operator_selected_courier_is_not_serviceable(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900633', 'DELHI-RETAIL');
        $created = $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => '15086',
            'selected_courier_name' => 'Shadowfax_Surface',
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => '15086',
            'courier_name' => 'Shadowfax_Surface',
        ])->save();

        $this->fake->courierOptions = [
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0', 'provider_recommended' => true],
            ['courier_id' => '15086', 'courier_name' => 'Shadowfax_Surface', 'mode' => '0'],
        ];
        $this->fake->recommendedCourierId = '15084';
        $this->fake->assignModeQueue = ['courier_not_serviceable', 'accepted'];

        $assigned = $this->shipments->assignAwb($fulfilment->fresh(['shipment']));

        $this->assertNotNull($assigned->awb);
        $this->assertSame(['15086', '15084'], $this->fake->assignCourierIds);
        $this->assertSame('15084', $assigned->courier_id);
        $this->assertSame('15084', $fulfilment->fresh()->selected_courier_id);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->fresh()->state);
        $this->assertSame($created->external_shipment_id, $assigned->external_shipment_id);
        $this->assertSame(2, $this->fake->awbs);
    }

    public function test_awb_uses_preferred_courier_when_stored_is_not_serviceable(): void
    {
        config(['shipping.preferred_courier_ids' => ['15084']]);
        $fulfilment = $this->invoicedFulfilment('RDE900632', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => '51',
            'selected_courier_name' => 'Xpressbees Surface',
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => '51',
            'courier_name' => 'Xpressbees Surface',
        ])->save();

        $this->fake->courierOptions = [
            ['courier_id' => '48', 'courier_name' => 'Ekart Logistics Air', 'mode' => '1'],
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
        ];
        $this->fake->recommendedCourierId = '48';

        $assigned = $this->shipments->assignAwb($fulfilment->fresh(['shipment']));

        $this->assertSame(['15084'], $this->fake->assignCourierIds);
        $this->assertSame('15084', $assigned->courier_id);
        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_awb_recovers_with_alternate_recommended_courier_after_courier_not_serviceable(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900616', 'DELHI-RETAIL');
        $created = $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => '15084',
            'selected_courier_name' => 'Delhivery_Surface',
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => '15084',
            'courier_name' => 'Delhivery_Surface',
        ])->save();

        $this->fake->courierOptions = [
            ['courier_id' => '369', 'courier_name' => 'Shree Maruti 500 g Surface'],
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface'],
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface'],
        ];
        $this->fake->recommendedCourierId = '369';
        $this->fake->assignModeQueue = ['courier_not_serviceable', 'accepted'];

        $courierListsBeforeAssign = $this->fake->courierLists;
        $assigned = $this->shipments->assignAwb($fulfilment->fresh(['shipment']));

        $this->assertNotNull($assigned->awb);
        $this->assertSame(['15084', '369'], $this->fake->assignCourierIds);
        $this->assertSame('369', $this->fake->lastAssignCourierId);
        $this->assertSame(2, $this->fake->awbs);
        $this->assertSame($courierListsBeforeAssign + 2, $this->fake->courierLists);
        $this->assertSame('369', $fulfilment->fresh()->selected_courier_id);
        $this->assertSame('Shree Maruti 500 g Surface', $fulfilment->fresh()->selected_courier_name);
        $this->assertSame('369', $assigned->courier_id);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame($created->external_shipment_id, $assigned->external_shipment_id);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->fresh()->state);
        $this->assertSame('awb_recovery', $fulfilment->fresh()->courier_options_snapshot['quoted_for'] ?? null);
    }

    public function test_awb_does_not_retry_after_generic_http_400_rejection(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900617', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $this->fake->recommendedCourierId = '44';
        $this->fake->assignModeQueue = ['rejected'];
        $courierListsBeforeAssign = $this->fake->courierLists;

        try {
            $this->shipments->assignAwb($fulfilment->fresh());
            $this->fail('Generic AWB rejection must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('rejected AWB assignment', implode(' ', $exception->errors()['shipping'] ?? []));
            $this->assertStringNotContainsString('not serviceable', strtolower(implode(' ', $exception->errors()['shipping'] ?? [])));
        }

        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame($courierListsBeforeAssign + 1, $this->fake->courierLists);
        $this->assertSame('12', $fulfilment->fresh()->selected_courier_id);
    }

    public function test_awb_does_not_retry_after_timeout_on_first_assign(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900618', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $this->fake->recommendedCourierId = '44';
        $this->fake->assignModeQueue = ['timeout'];
        $courierListsBeforeAssign = $this->fake->courierLists;

        try {
            $this->shipments->assignAwb($fulfilment->fresh());
            $this->fail('Timeout must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Reconcile', implode(' ', $exception->errors()['shipping'] ?? []));
        }

        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame($courierListsBeforeAssign + 1, $this->fake->courierLists);
    }

    public function test_awb_does_not_retry_after_auth_failure_on_first_assign(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900619', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $this->fake->recommendedCourierId = '44';
        $this->fake->assignModeQueue = ['auth_failed'];
        $courierListsBeforeAssign = $this->fake->courierLists;

        try {
            $this->shipments->assignAwb($fulfilment->fresh());
            $this->fail('Authentication failure must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('authentication failed', strtolower(implode(' ', $exception->errors()['shipping'] ?? [])));
        }

        $this->assertSame(1, $this->fake->awbs);
        $this->assertSame($courierListsBeforeAssign + 1, $this->fake->courierLists);
    }

    public function test_awb_recovers_with_eligible_alternate_when_recommendation_is_missing(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900620', 'DELHI-RETAIL');
        $created = $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => '15084',
            'selected_courier_name' => 'Delhivery_Surface',
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => '15084',
            'courier_name' => 'Delhivery_Surface',
        ])->save();

        $this->fake->courierOptions = [
            ['courier_id' => '15106', 'courier_name' => 'Blue Dart Advantage Surface', 'mode' => '0'],
            ['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface', 'mode' => '0'],
        ];
        $this->fake->recommendedCourierId = '369';
        $this->fake->assignModeQueue = ['courier_not_serviceable', 'accepted'];

        $assigned = $this->shipments->assignAwb($fulfilment->fresh(['shipment']));

        $this->assertNotNull($assigned->awb);
        $this->assertSame(['15084', '15106'], $this->fake->assignCourierIds);
        $this->assertSame('15106', $assigned->courier_id);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame($created->external_shipment_id, $assigned->external_shipment_id);
    }

    public function test_awb_fails_closed_when_no_current_courier_is_serviceable(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900613', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $this->fake->courierOptions = [
            ['courier_id' => '44', 'courier_name' => 'Fake Express'],
        ];
        $this->fake->recommendedCourierId = null;

        try {
            $this->shipments->assignAwb($fulfilment->fresh());
            $this->fail('Missing current courier must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no longer serviceable', implode(' ', $exception->errors()['courier_id'] ?? []));
        }

        $this->assertSame(0, $this->fake->awbs);
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame('12', $fulfilment->fresh()->selected_courier_id);
        $this->assertNull($fulfilment->fresh()->shipment?->awb);
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
    }

    public function test_awb_auth_failure_is_distinct_from_courier_rejection(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900614', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $this->fake->nextCourierListMode = 'auth_failed';

        try {
            $this->shipments->assignAwb($fulfilment->fresh());
            $this->fail('Authentication failure must fail closed.');
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['shipping'] ?? []);
            $this->assertStringContainsString('authentication failed', strtolower($message));
            $this->assertStringNotContainsString('not serviceable', strtolower($message));
        }

        $this->assertSame(0, $this->fake->awbs);
    }

    public function test_awb_dns_timeout_is_distinct_from_courier_rejection(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900615', 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $this->fake->nextCourierListMode = 'timeout';

        try {
            $this->shipments->assignAwb($fulfilment->fresh());
            $this->fail('Timeout must fail closed.');
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['shipping'] ?? []);
            $this->assertStringContainsString('timeout', strtolower($message));
            $this->assertStringContainsString('not a courier-serviceability rejection', $message);
        }

        $this->assertSame(0, $this->fake->awbs);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_timeout_reconciles_by_search_before_create(): void
    {
        $this->fake->nextCreateMode = 'timeout_accepted';
        $fulfilment = $this->invoicedFulfilment('RDE900511', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Timeout must surface as reconcile-required.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Reconcile', implode(' ', $exception->errors()['shipping'] ?? []));
        }

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame(1, $this->fake->creates);

        $bound = $this->shipments->createShipment($fulfilment->fresh());

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->searches);
        $this->assertTrue($bound->isBound());
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_existing_provider_shipment_is_reconciled_without_create(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900519', 'DELHI-RETAIL');
        $invoice = StatutoryInvoice::query()->findOrFail($fulfilment->statutory_invoice_id);
        Shipment::query()->create([
            'shipment_no' => 'HW-RDE900519',
            'commerce_order_id' => $fulfilment->commerce_order_id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => 'test',
            'status' => ShipmentStatus::Ambiguous,
            'invoice_number' => $invoice->invoice_number,
            'serial_numbers' => ['SN-RDE900519-001'],
            'pickup_location' => 'TEST-DELHI-PICKUP',
            'idempotency_key' => 'hardware:shiprocket:create:'.$fulfilment->id,
            'correlation_id' => (string) Str::uuid(),
            'failure_class' => 'ambiguous',
            'last_error' => 'Previous create timed out.',
            'attempts' => 1,
        ]);
        $this->fake->seedCatalog('HW-RDE900519', 'SR-EXIST-ORD', 'SR-EXIST-SHP');

        $bound = $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items']));

        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(1, $this->fake->searches);
        $this->assertSame('SR-EXIST-ORD', $bound->external_order_id);
        $this->assertSame('SR-EXIST-SHP', $bound->external_shipment_id);
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_retryable_search_does_not_create_another_provider_order(): void
    {
        $this->fake->nextCreateMode = 'timeout_accepted';
        $fulfilment = $this->invoicedFulfilment('RDE900522', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Timeout must surface as reconcile-required.');
        } catch (ValidationException) {
            // expected
        }

        $this->fake->nextSearchMode = 'timeout';

        try {
            $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items']));
            $this->fail('Retryable search must not create.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Reconcile', implode(' ', $exception->errors()['shipping'] ?? []));
        }

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->searches);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertFalse(Shipment::query()->whereNotNull('external_shipment_id')->exists());
        Http::assertNothingSent();
    }

    public function test_provider_authentication_failure_is_handled_safely(): void
    {
        $this->fake->nextCreateMode = 'auth_failed';
        $fulfilment = $this->invoicedFulfilment('RDE900520', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Authentication failure must surface.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('authentication failed', strtolower(implode(' ', $exception->errors()['shipping'] ?? [])));
        }

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertFalse(Shipment::query()->whereNotNull('external_shipment_id')->exists());
        Http::assertNothingSent();
    }

    public function test_provider_5xx_is_retryable_and_does_not_advance_state(): void
    {
        $this->fake->nextCreateMode = 'retryable';
        $fulfilment = $this->invoicedFulfilment('RDE900512', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Retryable provider failure must surface.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertFalse(Shipment::query()->whereNotNull('external_shipment_id')->exists());
    }

    public function test_provider_validation_failure_searches_before_a_second_create(): void
    {
        $this->fake->nextCreateMode = 'rejected';
        $fulfilment = $this->invoicedFulfilment('RDE900513', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Rejected create must fail.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, $this->fake->creates);
        $failed = Shipment::query()->first();
        $this->assertNotNull($failed);
        $this->assertSame('provider_rejected', $failed->failure_class);
        $this->assertNull($failed->external_order_id);
        $this->assertNull($failed->external_shipment_id);
        $this->assertNull($failed->awb);

        $this->fake->nextCreateMode = 'rejected';

        try {
            $this->shipments->createShipment($fulfilment->fresh());
            $this->fail('Unchanged rejected payload must fail again after search.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, $this->fake->searches);
        $this->assertSame(2, $this->fake->creates);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertFalse(Shipment::query()->whereNotNull('external_shipment_id')->exists());
    }

    public function test_provider_validation_retry_binds_existing_search_hit_without_a_second_create(): void
    {
        $this->fake->nextCreateMode = 'rejected';
        $fulfilment = $this->invoicedFulfilment('RDE900524', 'DELHI-RETAIL');

        try {
            $this->shipments->createShipment($fulfilment);
            $this->fail('Rejected create must fail.');
        } catch (ValidationException) {
            // expected
        }

        $this->fake->seedCatalog('HW-RDE900524', 'SR-REJ-ORD', 'SR-REJ-SHP');
        $bound = $this->shipments->createShipment($fulfilment->fresh(['commerceOrder.items']));

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->searches);
        $this->assertTrue($bound->isBound());
        $this->assertSame('SR-REJ-ORD', $bound->external_order_id);
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_provider_ids_are_preserved_on_duplicate_operator_request(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900514', 'DELHI-RETAIL');
        $first = $this->shipments->createShipment($fulfilment);
        $ids = [$first->external_order_id, $first->external_shipment_id];
        $second = $this->shipments->createShipment($fulfilment->fresh());

        $this->assertSame($ids, [$second->external_order_id, $second->external_shipment_id]);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_external_provider_awb_reconciles_without_assign_call_and_updates_courier(): void
    {
        $fulfilment = $this->boundShipmentWithoutAwb('RDE960001', '15086', 'Shadowfax_Surface');
        $shipment = Shipment::query()->firstOrFail();
        $this->fake->seedAwb(
            (string) $shipment->external_shipment_id,
            '284931180089754',
            '15084',
            'Delhivery_Surface',
        );

        $awbsBefore = $this->fake->awbs;
        $outcome = $this->shipments->reconcileAwbFromProviderSearch($fulfilment->fresh(['shipment']), $this->actor);

        $this->assertTrue($outcome->reconciled);
        $this->assertFalse($outcome->alreadyLocal);
        $this->assertSame($awbsBefore, $this->fake->awbs);
        $this->assertSame(1, $this->fake->searches);

        $shipment = $shipment->fresh();
        $this->assertSame('284931180089754', $shipment->awb);
        $this->assertSame('15084', $shipment->courier_id);
        $this->assertSame('Delhivery_Surface', $shipment->courier_name);
        $this->assertNull($shipment->failure_class);
        $this->assertNull($shipment->last_error);
        $this->assertSame('15084', $fulfilment->fresh()->selected_courier_id);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->fresh()->state);

        $this->assertNotNull(ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'reconcile')
            ->where('activity', 'awb_assigned')
            ->first());
    }

    public function test_awb_reconcile_is_idempotent_when_local_awb_already_matches_provider(): void
    {
        $fulfilment = $this->boundShipmentWithoutAwb('RDE960002', '15086', 'Shadowfax_Surface');
        $shipment = Shipment::query()->firstOrFail();
        $this->fake->seedAwb(
            (string) $shipment->external_shipment_id,
            'AWB-IDEM-001',
            '15084',
            'Delhivery_Surface',
        );

        $this->shipments->reconcileAwbFromProviderSearch($fulfilment->fresh(['shipment']), $this->actor);
        $eventsAfterFirst = ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'reconcile')
            ->count();

        $outcome = $this->shipments->reconcileAwbFromProviderSearch($fulfilment->fresh(['shipment']), $this->actor);

        $this->assertTrue($outcome->alreadyLocal);
        $this->assertFalse($outcome->reconciled);
        $this->assertSame($eventsAfterFirst, ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'reconcile')
            ->count());
    }

    public function test_awb_reconcile_refuses_identity_mismatch(): void
    {
        $fulfilment = $this->boundShipmentWithoutAwb('RDE960003', '15086', 'Shadowfax_Surface');
        $shipment = Shipment::query()->firstOrFail();

        $this->fake->nextSearchResult = new ShiprocketSearchResult(
            provider: 'shiprocket',
            found: true,
            externalOrderId: '9999999999',
            externalShipmentId: (string) $shipment->external_shipment_id,
            merchantOrderId: 'HW-RDE960003',
            awb: 'AWB-MISMATCH',
            courierId: '15084',
            courierName: 'Delhivery_Surface',
        );

        try {
            $this->shipments->reconcileAwbFromProviderSearch($fulfilment->fresh(['shipment']), $this->actor);
            $this->fail('Identity mismatch must refuse reconciliation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'order id does not match',
                strtolower(implode(' ', $exception->errors()['shipping'] ?? [])),
            );
        }

        $this->assertNull($shipment->fresh()->awb);
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
    }

    public function test_pickup_generated_track_reconciles_pickup_state(): void
    {
        $fulfilment = $this->boundShipmentWithoutAwb('RDE960005', '15084', 'Delhivery_Surface');
        $shipment = Shipment::query()->firstOrFail();
        $this->fake->seedAwb(
            (string) $shipment->external_shipment_id,
            'AWB-PICKUP-001',
            '15084',
            'Delhivery_Surface',
        );
        $this->shipments->reconcileAwbFromProviderSearch($fulfilment->fresh(['shipment']), $this->actor);

        $this->fake->trackByAwbQueue = [
            FakeShiprocketGateway::pickupGeneratedTrack('AWB-PICKUP-001'),
        ];

        $outcome = app(HardwareShipmentDocumentsService::class)
            ->reconcilePickupFromProviderTrack($fulfilment->fresh(['shipment']), $this->actor);

        $this->assertTrue($outcome->reconciledProviderAdvanced);
        $this->assertNotNull($outcome->shipment->pickup_requested_at);
        $this->assertSame('Pickup Generated', $outcome->shipment->provider_track_status);
        $this->assertSame('pickup_queued', $outcome->shipment->provider_track_normalized);
        $this->assertSame(0, $this->fake->pickups);
    }

    public function test_frozen_pending_orders_are_not_shipped(): void
    {
        foreach (HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS as $sourceId) {
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
        }

        $this->assertSame(0, CommerceOrder::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, HardwareFulfilment::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());

        try {
            $this->shipments->createShipment(new HardwareFulfilment(['source_id' => 'RDE318360']));
            $this->fail('Frozen source ids must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Frozen', implode(' ', $exception->errors()['fulfilment'] ?? []));
        }

        $this->assertSame(0, $this->fake->creates);
    }

    private function boundShipmentWithoutAwb(
        string $sourceId,
        string $courierId,
        string $courierName,
    ): HardwareFulfilment {
        $fulfilment = $this->invoicedFulfilment($sourceId, 'DELHI-RETAIL');
        $this->shipments->createShipment($fulfilment);
        $fulfilment->forceFill([
            'selected_courier_id' => $courierId,
            'selected_courier_name' => $courierName,
        ])->save();
        $fulfilment->shipment?->forceFill([
            'courier_id' => $courierId,
            'courier_name' => $courierName,
            'failure_class' => 'provider_rejected',
            'last_error' => 'HTTP 400 — Given courier not serviceable.',
        ])->save();

        return $fulfilment->fresh(['shipment']) ?? $fulfilment;
    }

    private function invoicedFulfilment(
        string $sourceId,
        string $branchCode,
        string $placeOfSupply = 'Delhi',
        int $qty = 1,
    ): HardwareFulfilment {
        $fulfilment = $this->allocatedFulfilment($sourceId, $branchCode, $placeOfSupply, $qty);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        $ready = $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
        try {
            return $this->selectTestCourier($ready, $this->actor);
        } catch (ValidationException) {
            return $ready;
        }
    }

    private function allocatedFulfilment(
        string $sourceId,
        string $branchCode,
        string $placeOfSupply = 'Delhi',
        int $qty = 1,
    ): HardwareFulfilment {
        $fulfilment = $this->readyFulfilment($sourceId, $branchCode, $placeOfSupply, $qty);
        $serials = [];
        for ($index = 1; $index <= $qty; $index++) {
            $serials[] = sprintf('SN-%s-%03d', $sourceId, $index);
        }
        $this->stockAt($branchCode, $serials);
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment,
            $serials,
            $this->actor,
        );

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function readyFulfilment(
        string $sourceId,
        string $branchCode,
        string $placeOfSupply = 'Delhi',
        int $qty = 1,
    ): HardwareFulfilment {
        $this->mapModel(951);
        $fulfilment = $this->ingestHardware($sourceId, $placeOfSupply, $qty);
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

    private function ingestHardware(string $sourceId, string $placeOfSupply, int $qty = 1): HardwareFulfilment
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
                'city' => 'Indore',
                'state' => $placeOfSupply,
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
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => $qty,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => round(2583.90 * $qty, 2),
                'tax_total' => round(465.10 * $qty, 2),
                'line_total' => round(3049.00 * $qty, 2),
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
