<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HardwareFulfilmentP0M2BranchDerivationTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareSerialAllocationService $allocation;

    private HardwareFulfilmentWorkflowService $workflow;

    private User $actor;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.sku_map' => [],
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'shipping.enabled' => false,
        ]);

        $this->allocation = app(HardwareSerialAllocationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-MSO-TEST',
            'name' => 'Desk MSO test product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_delhi_serials_set_fulfilment_branch_to_delhi_retail(): void
    {
        $fulfilment = $this->readyUnsetFulfilment('RDE900701', placeOfSupply: 'Maharashtra');
        $this->assertNull($fulfilment->fulfilment_branch_id);
        $delhi = $this->stockAt('DELHI-RETAIL', ['SN-M2-D-001']);

        $allocated = $this->allocation->allocateSerials($fulfilment, ['SN-M2-D-001'], $this->actor);

        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $allocated->state);
        $this->assertSame($delhi->id, $allocated->fulfilment_branch_id);
        $this->assertSame('DELHI-RETAIL', InventoryBranch::query()->find($allocated->fulfilment_branch_id)?->code);
        $this->assertSame('Maharashtra', $allocated->commerceOrder?->place_of_supply_state);
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'SN-M2-D-001')->value('status'));
    }

    public function test_mumbai_serials_set_fulfilment_branch_to_mumbai(): void
    {
        $fulfilment = $this->readyUnsetFulfilment('RDE900702', placeOfSupply: 'Delhi');
        $this->assertNull($fulfilment->fulfilment_branch_id);
        $mumbai = $this->stockAt('MUMBAI', ['SN-M2-M-001']);

        $allocated = $this->allocation->allocateSerials($fulfilment, ['SN-M2-M-001'], $this->actor);

        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $allocated->state);
        $this->assertSame($mumbai->id, $allocated->fulfilment_branch_id);
        $this->assertSame('MUMBAI', InventoryBranch::query()->find($allocated->fulfilment_branch_id)?->code);
        $this->assertSame('Delhi', $allocated->commerceOrder?->place_of_supply_state);
    }

    public function test_mixed_delhi_and_mumbai_serials_are_rejected_without_mutation(): void
    {
        $fulfilment = $this->readyUnsetFulfilment('RDE900703', qty: 2);
        $this->stockAt('DELHI-RETAIL', ['SN-M2-MIX-D']);
        $this->stockAt('MUMBAI', ['SN-M2-MIX-M']);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-M2-MIX-D', 'SN-M2-MIX-M'], $this->actor);
            $this->fail('Mixed physical branches must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('multiple physical stock branches', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $fresh = $fulfilment->fresh();
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fresh->state);
        $this->assertNull($fresh->fulfilment_branch_id);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-M2-MIX-D')->value('status'));
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-M2-MIX-M')->value('status'));
    }

    public function test_unset_fulfilment_can_search_serials_filtered_by_physical_branch(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fulfilment = $this->readyUnsetFulfilment('RDE900704');
        $this->stockAt('DELHI-RETAIL', ['SN-M2-SRC-D']);
        $this->stockAt('MUMBAI', ['SN-M2-SRC-M']);
        $operator = $this->hardwareOperator(['DELHI-RETAIL', 'MUMBAI']);
        $itemId = (int) $fulfilment->commerceOrder?->items->first()?->id;

        $this->actingAs($operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Delhi 1')
            ->assertSee('Mumbai 1');

        $this->actingAs($operator)
            ->getJson(route('inventory.hardware-fulfilments.serials.search', [
                'fulfilment' => $fulfilment->id,
                'commerce_order_item_id' => $itemId,
                'q' => 'SN-M2-SRC',
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'serials')
            ->assertJsonPath('serials.0.serial_number', 'SN-M2-SRC-D')
            ->assertJsonPath('serials.0.branch_code', 'DELHI-RETAIL')
            ->assertJsonPath('serials.1.serial_number', 'SN-M2-SRC-M')
            ->assertJsonPath('serials.1.branch_code', 'MUMBAI');

        $this->actingAs($operator)
            ->getJson(route('inventory.hardware-fulfilments.serials.search', [
                'fulfilment' => $fulfilment->id,
                'commerce_order_item_id' => $itemId,
                'q' => 'SN-M2-SRC',
                'branch' => 'DELHI-RETAIL',
            ]))
            ->assertOk()
            ->assertJsonPath('serials.0.serial_number', 'SN-M2-SRC-D')
            ->assertJsonPath('serials.0.branch_code', 'DELHI-RETAIL')
            ->assertJsonCount(1, 'serials');

        $this->actingAs($operator)
            ->getJson(route('inventory.hardware-fulfilments.serials.search', [
                'fulfilment' => $fulfilment->id,
                'commerce_order_item_id' => $itemId,
                'q' => 'SN-M2-SRC',
                'branch' => 'MUMBAI',
            ]))
            ->assertOk()
            ->assertJsonPath('serials.0.serial_number', 'SN-M2-SRC-M')
            ->assertJsonPath('serials.0.branch_code', 'MUMBAI')
            ->assertJsonCount(1, 'serials');
    }

    public function test_claimed_ui_branch_that_differs_from_serial_branch_is_rejected(): void
    {
        $fulfilment = $this->readyUnsetFulfilment('RDE900705');
        $this->stockAt('MUMBAI', ['SN-M2-TAMPER']);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-M2-TAMPER'], $this->actor, 'DELHI-RETAIL');
            $this->fail('Tampered claimed branch must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('claimed stock branch', implode(' ', $exception->errors()['branch'] ?? []));
        }

        $fresh = $fulfilment->fresh();
        $this->assertNull($fresh->fulfilment_branch_id);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fresh->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-M2-TAMPER')->value('status'));
    }

    public function test_same_serial_cannot_be_allocated_twice_and_retry_does_not_change_branch(): void
    {
        $first = $this->readyUnsetFulfilment('RDE900706');
        $second = $this->readyUnsetFulfilment('RDE900707');
        $delhi = $this->stockAt('DELHI-RETAIL', ['SN-M2-DUP', 'SN-M2-OTHER']);
        $allocated = $this->allocation->allocateSerials($first, ['SN-M2-DUP'], $this->actor);
        $retry = $this->allocation->allocateSerials($first->fresh(), ['SN-M2-DUP'], $this->actor);

        $this->assertSame($delhi->id, $retry->fulfilment_branch_id);
        $this->assertSame(1, HardwareFulfilmentSerial::query()->where('inventory_serial_id', InventorySerial::query()->where('serial_number', 'SN-M2-DUP')->value('id'))->count());

        try {
            $this->allocation->allocateSerials($second, ['SN-M2-DUP'], $this->actor);
            $this->fail('Duplicate allocation must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already allocated', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertNull($second->fresh()->fulfilment_branch_id);
        $this->assertSame($delhi->id, $allocated->fresh()->fulfilment_branch_id);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $second->fresh()->state);
    }

    public function test_invoice_and_shipment_still_require_fulfilment_branch_not_customer_state(): void
    {
        $fulfilment = $this->readyUnsetFulfilment('RDE900708', placeOfSupply: 'Maharashtra');

        try {
            app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);
            $this->fail('Invoice before allocation must fail.');
        } catch (ValidationException $exception) {
            $this->assertNotSame([], $exception->errors());
        }

        $this->stockAt('DELHI-RETAIL', ['SN-M2-GATE']);
        $allocated = $this->allocation->allocateSerials($fulfilment, ['SN-M2-GATE'], $this->actor);
        $this->assertSame('DELHI-RETAIL', InventoryBranch::query()->find($allocated->fulfilment_branch_id)?->code);
        $this->assertSame('Maharashtra', $allocated->commerceOrder?->place_of_supply_state);

        $allocated->forceFill(['fulfilment_branch_id' => null])->save();

        try {
            app(HardwareFulfilmentInvoiceService::class)->issueInvoice($allocated->fresh());
            $this->fail('Invoice without fulfilment branch must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('fulfilment branch', implode(' ', $exception->errors()['branch'] ?? []));
            $this->assertStringContainsString('Customer state cannot substitute', implode(' ', $exception->errors()['branch'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());

        $allocated->forceFill(['fulfilment_branch_id' => $this->branch('DELHI-RETAIL')->id])->save();
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($allocated->fresh());
        $invoiced = $allocated->fresh();
        $invoiced->forceFill(['fulfilment_branch_id' => null])->save();

        try {
            app(HardwareShipmentEligibility::class)->require($invoiced->fresh(['commerceOrder.items']));
            $this->fail('Shipment without fulfilment branch must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('fulfilment branch', implode(' ', $exception->errors()['branch'] ?? []));
            $this->assertStringContainsString('Customer state cannot substitute', implode(' ', $exception->errors()['branch'] ?? []));
        }
    }

    public function test_frozen_seven_remain_untouched(): void
    {
        $this->assertSame([
            'RDE318360',
            'RDE318367',
            'RDE318378',
            'RDE318379',
            'RDE318382',
            'RDE318388',
            'RDE318391',
        ], HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS);

        $this->assertSame(0, CommerceOrder::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, HardwareFulfilment::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());

        try {
            $this->allocation->allocateSerials(new HardwareFulfilment(['source_id' => 'RDE318360']), ['SN-FROZEN'], $this->actor);
            $this->fail('Frozen source ids must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Frozen', implode(' ', $exception->errors()['fulfilment'] ?? []));
        }
    }

    public function test_http_allocate_derives_branch_and_rejects_tampered_claim(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fulfilment = $this->readyUnsetFulfilment('RDE900709');
        $this->stockAt('MUMBAI', ['SN-M2-HTTP']);
        $operator = $this->hardwareOperator(['DELHI-RETAIL', 'MUMBAI']);
        $itemId = (int) $fulfilment->commerceOrder?->items->first()?->id;

        $this->actingAs($operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), [
                'serials' => [$itemId => ['SN-M2-HTTP']],
                'claimed_branch' => 'DELHI-RETAIL',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('claimed_branch');

        $this->assertNull($fulfilment->fresh()->fulfilment_branch_id);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());

        $this->actingAs($operator)
            ->post(route('inventory.hardware-fulfilments.serials.store', $fulfilment), [
                'serials' => [$itemId => ['SN-M2-HTTP']],
            ])
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame('MUMBAI', InventoryBranch::query()->find($fulfilment->fresh()->fulfilment_branch_id)?->code);
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->fresh()->state);
    }

    public function test_delhi_only_operator_cannot_allocate_mumbai_serials(): void
    {
        $fulfilment = $this->readyUnsetFulfilment('RDE900710');
        $this->stockAt('MUMBAI', ['SN-M2-AUTH']);
        $delhiOnly = User::factory()->create(['is_active' => true]);
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $delhiOnly->id,
            'branch_id' => $this->branch('DELHI-RETAIL')->id,
        ]);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-M2-AUTH'], $delhiOnly);
            $this->fail('Delhi-only operator must not allocate Mumbai stock.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertNull($fulfilment->fresh()->fulfilment_branch_id);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-M2-AUTH')->value('status'));
    }

    public function test_unsupported_branch_and_already_sold_serials_are_rejected(): void
    {
        $fulfilment = $this->readyUnsetFulfilment('RDE900711');
        $other = InventoryBranch::query()->firstOrCreate(
            ['code' => 'PUNE'],
            ['name' => 'PUNE', 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $other->id,
        ]);
        app(InventoryStockService::class)->stockInSerialized($this->product, $other, ['SN-M2-PUNE'], $this->actor);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-M2-PUNE'], $this->actor);
            $this->fail('Unsupported branch must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('unsupported stock branch', implode(' ', $exception->errors()['branch'] ?? []));
        }

        $this->assertNull($fulfilment->fresh()->fulfilment_branch_id);

        $delhi = $this->stockAt('DELHI-RETAIL', ['SN-M2-SOLD']);
        InventorySerial::query()->where('serial_number', 'SN-M2-SOLD')->update([
            'status' => InventorySerialStatus::Sold,
        ]);

        try {
            $this->allocation->allocateSerials($fulfilment, ['SN-M2-SOLD'], $this->actor);
            $this->fail('Sold serial must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already sold', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertNull($fulfilment->fresh()->fulfilment_branch_id);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->where('status', HardwareFulfilmentSerialStatus::Allocated)->count());
        $this->assertSame($delhi->code, 'DELHI-RETAIL');
    }

    public function test_true_concurrent_db_allocation_is_unknown_on_sqlite(): void
    {
        $this->markTestSkipped(
            'UNKNOWN: phpunit.xml forces sqlite :memory:. True two-connection InnoDB row-lock races were not proven in this environment.'
        );
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

    private function readyUnsetFulfilment(
        string $sourceId,
        int $qty = 1,
        string $placeOfSupply = 'Maharashtra',
    ): HardwareFulfilment {
        $this->mapModel(951);
        $fulfilment = $this->ingestHardware($sourceId, $qty, $placeOfSupply);
        $this->assertNull($fulfilment->fulfilment_branch_id);
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
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MSO1300',
                'channel_sku' => '951',
                'notes' => 'Test-only Owner map. Not a production P0-M1 row.',
            ],
        );
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): InventoryBranch
    {
        $branch = $this->branch($branchCode);
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        app(InventoryStockService::class)->stockInSerialized($this->product, $branch, $serials, $this->actor);

        return $branch;
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }

    private function ingestHardware(string $sourceId, int $qty, string $placeOfSupply): HardwareFulfilment
    {
        $taxable = round(2583.90 * $qty, 2);
        $tax = round($taxable * 0.18, 2);
        $lineTotal = round($taxable + $tax, 2);
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
                'taxable_value' => $taxable,
                'tax_total' => $tax,
                'line_total' => $lineTotal,
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
