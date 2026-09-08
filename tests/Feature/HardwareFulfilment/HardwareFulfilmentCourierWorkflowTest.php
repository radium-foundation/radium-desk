<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentCourierWorkflowTest extends TestCase
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

    public function test_list_filters_by_order_serial_state_branch_and_shipment_status(): void
    {
        $delhi = $this->invoicedFulfilment('RDE930001', 'DELHI-RETAIL');
        $mumbai = $this->invoicedFulfilment('RDE930002', 'MUMBAI');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', [
                'order' => 'RDE930001',
                'serial' => 'SN-RDE930001-001',
                'state' => HardwareFulfilmentState::InvoiceIssued->value,
                'branch_id' => $this->branch('DELHI-RETAIL')->id,
                'shipment_status' => 'ready',
            ]))
            ->assertOk()
            ->assertSee('RDE930001')
            ->assertDontSee('RDE930002');

        $this->assertSame('invoice_issued', $delhi->state?->value);
        $this->assertSame('invoice_issued', $mumbai->state?->value);
    }

    public function test_readiness_blocks_courier_options_and_create(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE930010', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Invoice required')
            ->assertDontSee('Get Courier Options')
            ->assertDontSee('Create Shipment');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->fake->courierLists);
        $this->assertSame(0, $this->fake->creates);
    }

    public function test_courier_options_request_and_no_provider_recommendation(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE930011', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Courier options updated from Shiprocket.');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Fake Surface')
            ->assertSee('Fake Express')
            ->assertSee('Shiprocket returned options without a recommendation.')
            ->assertDontSee('Create Shipment');

        $this->assertSame(1, $this->fake->courierLists);
        $this->assertSame(0, $this->fake->creates);
        Http::assertNothingSent();
    }

    public function test_provider_recommendation_is_labeled_when_returned(): void
    {
        $this->fake->recommendedCourierId = '44';
        $fulfilment = $this->invoicedFulfilment('RDE930012', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertOk()
            ->assertSee('Shiprocket Recommended')
            ->assertSee('Fake Express')
            ->assertDontSee('Shiprocket returned options without a recommendation.');
    }

    public function test_courier_selection_and_arbitrary_id_rejection(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE930013', 'DELHI-RETAIL');
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment));

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.courier.store', $fulfilment), [
                'courier_id' => '99999',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('courier_id');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier.store', $fulfilment->fresh()), [
                'courier_id' => '12',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Courier selected.');

        $this->assertSame('12', $fulfilment->fresh()->selected_courier_id);
    }

    public function test_stale_courier_options_are_rejected_after_input_change(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE930014', 'DELHI-RETAIL');
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment));
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier.store', $fulfilment->fresh()), [
                'courier_id' => '12',
            ]);

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
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('courier_id');

        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
    }

    public function test_create_requires_selected_courier_and_persists_it(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE930015', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('courier_id');

        $this->selectTestCourier($fulfilment, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Shipment created.');

        $shipment = Shipment::query()->firstOrFail();
        $this->assertSame('12', $shipment->courier_id);
        $this->assertSame('Fake Surface', $shipment->courier_name);
        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_create_rejects_client_supplied_courier_id(): void
    {
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE930016', 'DELHI-RETAIL'), $this->admin);

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment), [
                'courier_id' => '44',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('courier_id');

        $this->assertSame(0, $this->fake->creates);
    }

    public function test_double_submit_and_ambiguous_timeout_do_not_create_twice(): void
    {
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE930017', 'DELHI-RETAIL'), $this->admin);
        $this->fake->nextCreateMode = 'timeout_accepted';

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame(1, $this->fake->creates);
        $this->assertSame(1, $this->fake->searches);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertTrue(Shipment::query()->firstOrFail()->isBound());
        Http::assertNothingSent();
    }

    public function test_awb_persists_and_is_displayed(): void
    {
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment('RDE930018', 'DELHI-RETAIL'), $this->admin);
        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment));

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.awb.store', $fulfilment->fresh()), [
                'awb' => 'TYPED-AWB',
                'courier_id' => '99',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors(['awb', 'courier_id']);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.awb.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'AWB assigned.');

        $fresh = $fulfilment->fresh();
        $this->assertNotNull($fresh->awb);
        $this->assertSame($fresh->awb, $fresh->shipment?->awb);
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fresh->state);

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fresh))
            ->assertOk()
            ->assertSee($fresh->awb)
            ->assertDontSee('Assign AWB');

        $this->assertSame(1, $this->fake->awbs);
        Http::assertNothingSent();
    }

    public function test_permissions_remain_on_operate_and_strangers_are_forbidden(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE930019', 'DELHI-RETAIL');
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertForbidden();

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.courier.store', $fulfilment->fresh()), [
                'courier_id' => '12',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertTrue($this->operator->can(RolePermissionSeeder::PERMISSION_HARDWARE_FULFILMENT_OPERATE));
        $this->assertTrue($this->admin->can(RolePermissionSeeder::PERMISSION_HARDWARE_FULFILMENT_OPERATE));
        $this->assertSame(1, $this->fake->courierLists);
    }

    public function test_courier_options_timeout_does_not_create_a_shipment(): void
    {
        $this->fake->nextCourierListMode = 'timeout';
        $fulfilment = $this->invoicedFulfilment('RDE930020', 'DELHI-RETAIL');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.courier-options.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('shipping');

        $this->assertSame(0, Shipment::query()->count());
        $this->assertNull($fulfilment->fresh()->selected_courier_id);
        Http::assertNothingSent();
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
                'notes' => 'Test-only courier workflow map.',
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
