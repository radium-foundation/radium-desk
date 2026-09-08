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
use App\Models\StatutoryInvoice;
use App\Models\User;
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

    public function test_provider_validation_failure_is_not_retried(): void
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

        try {
            $this->shipments->createShipment($fulfilment->fresh());
            $this->fail('Rejected create must not be retried.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not retried', strtolower(implode(' ', $exception->errors()['shipping'] ?? []).' '.$exception->getMessage()));
        }

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
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
