<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentCountryCorrectionService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareFulfilmentCountryCorrectionTest extends TestCase
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

    public function test_admin_can_record_fill_if_absent_country_overlay_with_audit(): void
    {
        $fulfilment = $this->allocatedWithoutCountry('RDE920001');
        $events = HardwareFulfilmentEvent::query()->count();
        $structured = $fulfilment->commerceOrder?->shipping_address_structured;
        $billing = $fulfilment->commerceOrder?->billing_address_structured;
        $paidAt = $fulfilment->commerceOrder?->paid_at;
        $invoiceId = $fulfilment->statutory_invoice_id;
        $serials = $fulfilment->serials()->pluck('serial_number')->all();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('India')
            ->assertDontSee('Missing — not inferred')
            ->assertDontSee('Record country')
            ->assertDontSee('name="country"', false)
            ->assertDontSee('Shipping country');

        $this->assertNull($fulfilment->fresh()->shipping_country_overlay);
        $beforeInspect = $fulfilment->fresh();
        $readyBeforeWrite = app(HardwareShipmentEligibility::class)->inspect($beforeInspect);
        $this->assertFalse($readyBeforeWrite->countryMissing);
        $this->assertFalse($readyBeforeWrite->canCorrectCountry);
        $this->assertSame('India', $readyBeforeWrite->country);
        $this->assertNull($beforeInspect->fresh()->shipping_country_overlay);

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.country.store', $fulfilment), [
                'country' => 'India',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHas('status', 'Shipping country recorded.');

        $fresh = $fulfilment->fresh(['commerceOrder', 'serials']);
        $this->assertSame('India', $fresh->shipping_country_overlay);
        $this->assertSame($this->admin->id, $fresh->shipping_country_overlay_by_user_id);
        $this->assertNotNull($fresh->shipping_country_overlay_at);
        $this->assertSame($fresh->commerceOrder?->id, $fresh->shipping_country_overlay_context['commerce_order_id']);
        $this->assertSame('RDE920001', $fresh->shipping_country_overlay_context['source_id']);
        $this->assertSame($structured, $fresh->commerceOrder?->shipping_address_structured);
        $this->assertArrayNotHasKey('country', $fresh->commerceOrder?->shipping_address_structured ?? []);
        $this->assertSame($billing, $fresh->commerceOrder?->billing_address_structured);
        $this->assertSame($paidAt?->toIso8601String(), $fresh->commerceOrder?->paid_at?->toIso8601String());
        $this->assertSame($invoiceId, $fresh->statutory_invoice_id);
        $this->assertSame($serials, $fresh->serials->pluck('serial_number')->all());
        $this->assertSame($events, HardwareFulfilmentEvent::query()->count());
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fresh->state);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fresh);
        $this->assertFalse($ready->countryMissing);
        $this->assertFalse($ready->canCorrectCountry);
        $this->assertStringContainsString('India', (string) $ready->shipTo);

        app(HardwareFulfilmentCountryCorrectionService::class)->correct($fresh, 'India', $this->admin);
        $this->assertSame('India', $fresh->fresh()->shipping_country_overlay);

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fresh))
            ->post(route('inventory.hardware-fulfilments.country.store', $fresh), [
                'country' => 'Bangladesh',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fresh))
            ->assertSessionHasErrors('country');

        $this->assertSame('India', $fresh->fresh()->shipping_country_overlay);
        Http::assertNothingSent();
    }

    public function test_hardware_team_cannot_correct_country(): void
    {
        $fulfilment = $this->allocatedWithoutCountry('RDE920002');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('India')
            ->assertDontSee('Missing — not inferred')
            ->assertDontSee('Record country')
            ->assertDontSee('name="country"', false);

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.country.store', $fulfilment), [
                'country' => 'India',
            ])
            ->assertForbidden();

        $this->assertNull($fulfilment->fresh()->shipping_country_overlay);
    }

    public function test_existing_structured_country_cannot_be_overwritten(): void
    {
        $fulfilment = $this->allocatedWithCountry('RDE920003');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertDontSee('Record country')
            ->assertDontSee('name="country"', false)
            ->assertSee('India');

        try {
            app(HardwareFulfilmentCountryCorrectionService::class)->correct($fulfilment, 'Nepal', $this->admin);
            $this->fail('Existing structured country must not be overwritten.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already present', implode(' ', $exception->errors()['country'] ?? []));
        }

        $this->assertNull($fulfilment->fresh()->shipping_country_overlay);
        $this->assertSame('India', $fulfilment->fresh()->commerceOrder?->shipping_address_structured['country'] ?? null);
    }

    public function test_empty_country_is_rejected_and_not_inferred(): void
    {
        $fulfilment = $this->allocatedWithoutCountry('RDE920004');

        $this->actingAs($this->admin)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.country.store', $fulfilment), [
                'country' => '   ',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('country');

        $this->assertNull($fulfilment->fresh()->shipping_country_overlay);
    }

    public function test_country_cannot_be_posted_on_create_shipment(): void
    {
        $fulfilment = $this->allocatedWithCountry('RDE920005');

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment), [
                'country' => 'India',
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors('country');
    }

    private function allocatedWithoutCountry(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->allocatedWithCountry($sourceId);
        $order = $fulfilment->commerceOrder;
        $structured = $order?->shipping_address_structured ?? [];
        unset($structured['country']);
        $order?->forceFill([
            'shipping_address_structured' => $structured,
            'billing_address_structured' => $order->billing_address_structured,
        ])->save();

        return $fulfilment->fresh(['commerceOrder', 'serials']) ?? $fulfilment;
    }

    private function allocatedWithCountry(string $sourceId): HardwareFulfilment
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
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branch('DELHI-RETAIL'),
            ['SN-'.$sourceId],
            $this->operator,
        );
        app(HardwareSerialAllocationService::class)->allocateSerials($fulfilment, ['SN-'.$sourceId], $this->operator);

        return $fulfilment->fresh(['commerceOrder', 'serials']) ?? $fulfilment;
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
            'billing_address' => [
                'line1' => '9 Billing Street',
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
