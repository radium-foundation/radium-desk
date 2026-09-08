<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackOutboxWriter;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Shipping\NullShiprocketGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentShipmentUiTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentWorkflowService $workflow;

    private FakeShiprocketGateway $fake;

    private User $operator;

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
            'shipping.pickup_postcodes.delhi' => '',
            'shipping.pickup_postcodes.mumbai' => '',
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
        $this->operator = $this->hardwareOperator(['DELHI-RETAIL', 'MUMBAI']);
    }

    public function test_ready_page_shows_shipment_blockers_and_hides_create_action(): void
    {
        $fulfilment = $this->readyFulfilment('RDE900901');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Shipment')
            ->assertSee('Not created')
            ->assertSee('Not allocated')
            ->assertSee('Not issued')
            ->assertSee('Serial allocation required')
            ->assertSee('Invoice required')
            ->assertSee('Shipping is not enabled')
            ->assertDontSee('Shiprocket (not called)')
            ->assertDontSee('Create Shipment')
            ->assertDontSee('Provider shipment is already bound')
            ->assertDontSee('Assign AWB')
            ->assertDontSee('name="pickup_location"', false)
            ->assertDontSee('name="claimed_branch"', false);

        $this->assertSame(0, $this->fake->creates);
        $this->assertNoLiveSideEffects();
    }

    public function test_page_shows_invoice_required_after_serials_only(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE900902', 'DELHI-RETAIL');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Invoice required')
            ->assertDontSee('Create Shipment');
    }

    public function test_page_shows_address_and_parcel_blockers(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900903', 'DELHI-RETAIL');
        $fulfilment->commerceOrder?->forceFill([
            'shipping_address_structured' => null,
            'parcel' => null,
        ])->save();

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Shipping address incomplete')
            ->assertSee('Parcel packaging not attached')
            ->assertSee('Not attached')
            ->assertSee('Incomplete')
            ->assertDontSee('id="hardware-shipment-form"', false)
            ->assertDontSee('id="hardware-shipment-submit"', false);
    }

    public function test_invoiced_page_stays_blocked_while_shiprocket_is_disabled(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE900904', 'DELHI-RETAIL');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('RADDELHI')
            ->assertSee('DELHI-RETAIL')
            ->assertSee('Shipping is not enabled')
            ->assertDontSee('id="hardware-shipment-form"', false)
            ->assertDontSee('id="hardware-shipment-submit"', false);

        $this->assertInstanceOf(NullShiprocketGateway::class, app(ShiprocketGateway::class));
    }

    public function test_create_action_appears_only_when_all_prerequisites_pass(): void
    {
        $this->enableFakeShipping();
        $fulfilment = $this->invoicedFulfilment('RDE900905', 'DELHI-RETAIL');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Get Courier Options')
            ->assertSee('Courier selection required')
            ->assertSee('Provider')
            ->assertSee('Shiprocket')
            ->assertSee('RADDELHI')
            ->assertDontSee('Create Shipment')
            ->assertDontSee('Assign AWB');

        $this->selectTestCourier($fulfilment, $this->operator);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Create Shipment')
            ->assertSee('Confirm shipment')
            ->assertSee('Fake Surface')
            ->assertDontSee('Assign AWB');
    }

    public function test_unauthorized_user_cannot_create_shipment(): void
    {
        $this->enableFakeShipping();
        $fulfilment = $this->invoicedFulfilment('RDE900906', 'DELHI-RETAIL');
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment))
            ->assertForbidden();

        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, $this->fake->creates);
    }

    public function test_operator_cannot_override_pickup_or_parcel(): void
    {
        $this->enableFakeShipping();
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE900907', 'DELHI-RETAIL'), $this->operator);

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment), [
                'pickup_location' => 'SOME-OTHER-WAREHOUSE',
                'branch' => 'MUMBAI',
                'weight' => 9.9,
                'country' => 'India',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors(['pickup_location', 'branch', 'weight', 'country']);

        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
    }

    public function test_authorized_create_persists_fake_shipment_without_live_side_effects(): void
    {
        $this->enableFakeShipping();
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE900908', 'DELHI-RETAIL'), $this->operator);
        $invoices = StatutoryInvoice::query()->count();
        $serials = HardwareFulfilmentSerial::query()->count();

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Shipment created.');

        $fresh = $fulfilment->fresh();
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fresh->state);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame($invoices, StatutoryInvoice::query()->count());
        $this->assertSame($serials, HardwareFulfilmentSerial::query()->count());
        $this->assertSame('HW-RDE900908', $fresh->shipment_no);
        $this->assertNotNull($fresh->provider_shipment_id);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fresh))
            ->assertOk()
            ->assertSee('SHIPMENT CREATED')
            ->assertDontSee('Not created')
            ->assertDontSee('Shiprocket (not called)')
            ->assertDontSee('Create Shipment')
            ->assertSee('Assign AWB')
            ->assertSee('Fake Surface')
            ->assertSee('Prepaid')
            ->assertDontSee('COD yes');

        $this->assertNoLiveSideEffects();
    }

    public function test_duplicate_ui_submit_does_not_create_a_second_shipment(): void
    {
        $this->enableFakeShipping();
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE900909', 'DELHI-RETAIL'), $this->operator);

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(1, $this->fake->creates);
        Http::assertNothingSent();
    }

    public function test_provider_rejection_shows_safe_reason_and_does_not_look_created(): void
    {
        $this->enableFakeShipping();
        $this->fake->nextCreateMode = 'rejected';
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE900910', 'DELHI-RETAIL'), $this->operator);

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $fresh = $fulfilment->fresh();
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fresh->state);
        $this->assertNull($fresh->provider_shipment_id);
        $this->assertNull($fresh->awb);

        $failed = Shipment::query()->first();
        $this->assertNotNull($failed);
        $this->assertSame('provider_rejected', $failed->failure_class);
        $this->assertNull($failed->external_order_id);
        $this->assertNull($failed->external_shipment_id);
        $this->assertNull($failed->awb);
        $this->assertNull($failed->label_url);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fresh))
            ->assertOk()
            ->assertSee('Not created — provider rejected')
            ->assertSee('Shiprocket rejected shipment creation:')
            ->assertSee('Fake provider rejected the create-order request.')
            ->assertDontSee('Provider validation error')
            ->assertDontSee('SHIPMENT CREATED')
            ->assertDontSee('Assign AWB')
            ->assertSee('Create Shipment');

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
                'notes' => 'Test-only shipment UI map. Not a production write.',
            ],
        );

        $fulfilment = $this->ingestHardware($sourceId);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

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

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }

    private function assertNoLiveSideEffects(): void
    {
        Http::assertNothingSent();
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
        $this->assertFalse((bool) config('shipping.http_enabled'));
        $this->assertFalse((bool) config('hardware_fulfilment.callback.enabled'));
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
