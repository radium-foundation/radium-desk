<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\Inventory\InventoryStockService;
use App\Services\Shipping\NullShiprocketGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HardwareFulfilmentShowReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentWorkflowService $workflow;

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
        $this->operator = $this->userWithRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_allocated_serial_does_not_show_serial_required_blocker_or_copy(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE930001', '10532319');

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $this->assertSame(['10532319'], $ready->serials);
        $this->assertSame(1, $ready->quantity);
        $this->assertNotContains('Serial allocation required', $ready->blockers);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('10532319')
            ->assertSee('Allocated')
            ->assertSee('Serial allocated')
            ->assertDontSee('1 serial required')
            ->assertDontSee('Serial allocation required');
    }

    public function test_missing_and_partial_serials_still_block(): void
    {
        $readyFulfilment = $this->readyFulfilment('RDE930002');
        $readyInspect = app(HardwareShipmentEligibility::class)->inspect($readyFulfilment);
        $this->assertContains('Serial allocation required', $readyInspect->blockers);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $readyFulfilment))
            ->assertOk()
            ->assertSee('1 serial required')
            ->assertSee('Serial allocation required')
            ->assertSee('Not allocated');

        $partial = $this->allocatedFulfilment('RDE930003', 'SN-RDE930003');
        $item = CommerceOrderItem::query()
            ->where('commerce_order_id', $partial->commerce_order_id)
            ->firstOrFail();
        $item->forceFill(['qty' => 2])->save();

        $partialInspect = app(HardwareShipmentEligibility::class)->inspect($partial->fresh(['commerceOrder.items', 'serials']));
        $this->assertContains('Serial allocation required', $partialInspect->blockers);
        $this->assertSame(['SN-RDE930003'], $partialInspect->serials);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $partial->fresh()))
            ->assertOk()
            ->assertSee('SN-RDE930003')
            ->assertSee('Partial (1 of 2)')
            ->assertSee('Serial allocation required');
    }

    public function test_invoice_identifier_links_to_canonical_invoice_and_authorizes_correctly(): void
    {
        $fulfilment = $this->invoicedFulfilment('RDE930004');
        $invoice = StatutoryInvoice::query()->findOrFail($fulfilment->statutory_invoice_id);
        $showUrl = route('finance.invoices.show', $invoice);
        $pdfUrl = route('finance.invoices.pdf', $invoice);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('View invoice')
            ->assertSee($showUrl, false)
            ->assertSee('GST PDF')
            ->assertSee($pdfUrl, false);

        $this->actingAs($this->operator)
            ->get($showUrl)
            ->assertOk()
            ->assertSee($invoice->invoice_number);

        $this->actingAs($this->admin)
            ->get($showUrl)
            ->assertOk()
            ->assertSee($invoice->invoice_number);

        $stranger = User::factory()->create(['is_active' => true]);
        $this->actingAs($stranger)
            ->get($showUrl)
            ->assertForbidden();

        $unlinked = $this->invoicedFulfilment('RDE930005');
        $orphan = StatutoryInvoice::query()->findOrFail($unlinked->statutory_invoice_id);
        $unlinked->forceFill(['statutory_invoice_id' => null])->save();
        $unlinked->commerceOrder?->forceFill(['statutory_invoice_id' => null])->save();

        $this->actingAs($this->operator)
            ->get(route('finance.invoices.show', $orphan))
            ->assertForbidden();

        $this->actingAs($this->operator)
            ->get(route('finance.invoices.index'))
            ->assertForbidden();
    }

    public function test_hardware_shipment_country_is_india_without_operator_input(): void
    {
        $fulfilment = $this->invoicedWithoutCountryOrParcel('RDE930006');
        $structured = $fulfilment->commerceOrder?->shipping_address_structured ?? [];
        $this->assertArrayNotHasKey('country', $structured);
        $this->assertNull($fulfilment->shipping_country_overlay);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $this->assertSame('India', $ready->country);
        $this->assertFalse($ready->countryMissing);
        $this->assertFalse($ready->canCorrectCountry);
        $this->assertStringContainsString('India', (string) $ready->shipTo);

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('India')
            ->assertDontSee('Record country')
            ->assertDontSee('name="country"', false)
            ->assertDontSee('Missing — not inferred')
            ->assertDontSee('not inferred');

        $fresh = $fulfilment->fresh(['commerceOrder']);
        $this->assertNull($fresh->shipping_country_overlay);
        $this->assertSame($structured, $fresh->commerceOrder?->shipping_address_structured);
    }

    public function test_parcel_gate_and_disabled_shiprocket_remain(): void
    {
        $fulfilment = $this->invoicedWithoutCountryOrParcel('RDE930007');

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $this->assertContains('Parcel packaging not attached', $ready->blockers);
        $this->assertContains('Shipping is not enabled', $ready->blockers);
        $this->assertFalse($ready->canCreate);
        $this->assertFalse($ready->canFetchCourierOptions);
        $this->assertInstanceOf(NullShiprocketGateway::class, app(ShiprocketGateway::class));
        $this->assertFalse((bool) config('shipping.enabled'));
        $this->assertFalse((bool) config('shipping.http_enabled'));

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Not attached')
            ->assertSee('Parcel packaging not attached')
            ->assertSee('Shipping is not enabled')
            ->assertDontSee('Create Shipment')
            ->assertDontSee('Get Courier Options')
            ->assertDontSee('Shiprocket (not called)');

        Http::assertNothingSent();
    }

    public function test_show_and_inspect_do_not_write_fulfilment_or_order(): void
    {
        $fulfilment = $this->invoicedWithoutCountryOrParcel('RDE930008');
        $before = [
            'state' => $fulfilment->state?->value,
            'updated_at' => $fulfilment->updated_at?->toIso8601String(),
            'parcel_snapshot' => $fulfilment->parcel_snapshot,
            'shipping_country_overlay' => $fulfilment->shipping_country_overlay,
            'shipment_id' => $fulfilment->shipment_id,
            'awb' => $fulfilment->awb,
            'statutory_invoice_id' => $fulfilment->statutory_invoice_id,
            'order_parcel' => $fulfilment->commerceOrder?->parcel,
            'order_updated_at' => $fulfilment->commerceOrder?->updated_at?->toIso8601String(),
            'order_structured' => $fulfilment->commerceOrder?->shipping_address_structured,
        ];

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk();

        app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh(['commerceOrder', 'serials']));

        $fresh = $fulfilment->fresh(['commerceOrder']);
        $this->assertSame($before['state'], $fresh->state?->value);
        $this->assertSame($before['updated_at'], $fresh->updated_at?->toIso8601String());
        $this->assertNull($fresh->parcel_snapshot);
        $this->assertNull($fresh->shipping_country_overlay);
        $this->assertNull($fresh->shipment_id);
        $this->assertNull($fresh->awb);
        $this->assertSame($before['statutory_invoice_id'], $fresh->statutory_invoice_id);
        $this->assertNull($fresh->commerceOrder?->parcel);
        $this->assertSame($before['order_updated_at'], $fresh->commerceOrder?->updated_at?->toIso8601String());
        $this->assertSame($before['order_structured'], $fresh->commerceOrder?->shipping_address_structured);
        Http::assertNothingSent();
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

    private function invoicedWithoutCountryOrParcel(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->invoicedFulfilment($sourceId);
        $order = $fulfilment->commerceOrder;
        $structured = $order?->shipping_address_structured ?? [];
        unset($structured['country']);
        $order?->forceFill([
            'shipping_address_structured' => $structured,
            'parcel' => null,
        ])->save();

        return $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
    }

    private function invoicedFulfilment(string $sourceId, ?string $serial = null): HardwareFulfilment
    {
        $fulfilment = $this->allocatedFulfilment($sourceId, $serial);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        return $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
    }

    private function allocatedFulfilment(string $sourceId, ?string $serial = null): HardwareFulfilment
    {
        $serial ??= 'SN-'.$sourceId;
        $fulfilment = $this->readyFulfilment($sourceId);
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branch('DELHI-RETAIL'),
            [$serial],
            $this->operator,
        );
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment,
            [$serial],
            $this->operator,
        );

        return $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
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
            ],
        );

        $fulfilment = $this->ingestHardware($sourceId);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
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
