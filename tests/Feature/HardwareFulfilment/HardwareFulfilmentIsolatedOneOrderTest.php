<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\HardwareFulfilment\BoxFulfilmentCallbackGateway;
use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackSigner;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use App\Services\HardwareFulfilment\NullBoxFulfilmentCallbackGateway;
use App\Services\Inventory\InventoryStockService;
use App\Services\Shipping\NullShiprocketGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\HardwareFulfilment\Support\FakeBoxFulfilmentCallbackGateway;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentIsolatedOneOrderTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private const CALLBACK_SECRET = 'test-desk-callback-secret';

    private HardwareFulfilmentIsolatedWorkflowService $isolated;

    private FakeShiprocketGateway $shiprocket;

    private FakeBoxFulfilmentCallbackGateway $callback;

    private User $actor;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        $this->shiprocket = new FakeShiprocketGateway;
        $this->callback = new FakeBoxFulfilmentCallbackGateway;
        $this->app->instance(ShiprocketGateway::class, $this->shiprocket);
        $this->app->instance(BoxFulfilmentCallbackGateway::class, $this->callback);

        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.callback.enabled' => false,
            'hardware_fulfilment.callback.inbound_enabled' => false,
            'hardware_fulfilment.callback.url' => '',
            'hardware_fulfilment.callback.secret' => '',
            'shipping.enabled' => true,
            'shipping.provider' => 'test',
            'shipping.http_enabled' => false,
            'shipping.pickup_locations.delhi' => 'RADDELHI',
            'shipping.pickup_locations.mumbai' => 'RADIUMUM',
            'shipping.pickup_postcodes.delhi' => '110001',
            'shipping.pickup_postcodes.mumbai' => '400001',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->isolated = app(HardwareFulfilmentIsolatedWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBIMSOE3L1',
            'name' => 'Desk MSO isolated test',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->mapApprovedSkus();
    }

    public function test_command_refuses_missing_and_batch_identifiers(): void
    {
        $this->artisan('desk:fulfil-hardware')
            ->expectsOutput('desk:fulfil-hardware requires exactly one explicit order or fulfilment id.')
            ->assertFailed();

        $this->artisan('desk:fulfil-hardware', ['id' => 'all'])->assertFailed();
        $this->artisan('desk:fulfil-hardware', ['id' => 'RDE900001,RDE900002'])->assertFailed();
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_date_cutoff_rejects_pre_5_sep_orders(): void
    {
        $this->expectException(ValidationException::class);
        $this->isolated->run(
            identifier: 'RDE900701',
            step: 'ingest',
            payload: $this->handoffPayload('RDE900701', orderedAt: '2026-09-04T23:59:59+05:30'),
        );
    }

    public function test_payment_gate_rejects_unpaid_handoff(): void
    {
        $this->expectException(ValidationException::class);
        $this->isolated->run(
            identifier: 'RDE900702',
            step: 'ingest',
            payload: $this->handoffPayload('RDE900702', paymentStatus: 'pending'),
        );
    }

    public function test_hardware_gate_rejects_service_only_handoff(): void
    {
        $payload = $this->handoffPayload('RDE900703');
        $payload['lines'][0]['shipping_line_kind'] = 'digital_service';
        $payload['lines'][0]['requires_shipping'] = false;
        unset($payload['lines'][0]['model_id']);

        $this->expectException(ValidationException::class);
        $this->isolated->run(
            identifier: 'RDE900703',
            step: 'ingest',
            payload: $payload,
        );
    }

    public function test_owner_hold_and_blocked_candidate_are_refused(): void
    {
        foreach (['RDE318438', 'RDE318400'] as $sourceId) {
            try {
                $this->isolated->run(
                    identifier: $sourceId,
                    step: 'ingest',
                    payload: $this->handoffPayload($sourceId),
                );
                $this->fail($sourceId.' must be refused.');
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_frozen_seven_remain_ineligible(): void
    {
        foreach (HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS as $sourceId) {
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
        }
    }

    public function test_dry_run_does_not_create_commerce_or_advance_state(): void
    {
        $result = $this->isolated->run(
            identifier: 'RDE900704',
            step: 'ingest',
            dryRun: true,
            payload: $this->handoffPayload('RDE900704'),
        );

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_ingest_ready_allocate_invoice_ship_awb_shipped_sync_state_machine(): void
    {
        $sourceId = 'RDE900705';
        $serial = 'SN-RDE900705-001';
        $this->stockAt('DELHI-RETAIL', [$serial]);

        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));
        $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
        $this->assertSame(HardwareFulfilmentState::Ingested, $fulfilment->state);

        $this->isolated->run(identifier: $sourceId, step: 'ready');
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);

        $this->isolated->run(
            identifier: $sourceId,
            step: 'allocate',
            serials: [$serial],
            actor: $this->actor,
        );
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->fresh()->state);
        $this->assertSame([$serial], $fulfilment->fresh()->serials->pluck('serial_number')->all());

        $invoiceResult = $this->isolated->run(identifier: $sourceId, step: 'invoice', actor: $this->actor);
        $invoice = StatutoryInvoice::query()->firstOrFail();
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertNotNull($invoice->document);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame('INV-671', $invoiceResult['invoice_number']);

        $again = $this->isolated->run(identifier: $sourceId, step: 'invoice', actor: $this->actor);
        $this->assertSame($invoice->id, $again['invoice_id']);
        $this->assertSame(1, StatutoryInvoice::query()->count());

        $this->selectTestCourier($fulfilment->fresh(), $this->actor);
        $this->isolated->run(identifier: $sourceId, step: 'ship');
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated, $fulfilment->fresh()->state);
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(1, $this->shiprocket->creates);
        $this->assertSame('RADDELHI', Shipment::query()->firstOrFail()->pickup_location);

        $this->isolated->run(identifier: $sourceId, step: 'ship');
        $this->assertSame(1, $this->shiprocket->creates);

        $this->isolated->run(identifier: $sourceId, step: 'awb');
        $this->assertSame(HardwareFulfilmentState::AwbAssigned, $fulfilment->fresh()->state);
        $this->assertNotSame('', (string) $fulfilment->fresh()->awb);

        $this->isolated->run(identifier: $sourceId, step: 'shipped');
        $this->assertSame(HardwareFulfilmentState::Shipped, $fulfilment->fresh()->state);

        config([
            'hardware_fulfilment.callback.url' => 'https://box.test/api/desk/fulfilment-status',
            'hardware_fulfilment.callback.secret' => self::CALLBACK_SECRET,
            'hardware_fulfilment.callback.enabled' => false,
        ]);

        $this->isolated->run(identifier: $sourceId, step: 'sync', forceCallback: true);
        $this->assertSame(HardwareFulfilmentState::Synced, $fulfilment->fresh()->state);
        $this->assertGreaterThan(0, $this->callback->sends);
        $this->assertFalse((bool) config('hardware_fulfilment.callback.enabled'));
        $this->assertSame(0, OutboxEvent::query()->where('event_type', '!=', 'hardware.box.callback')->count());
    }

    public function test_command_through_ready_uses_exactly_one_identifier(): void
    {
        $sourceId = 'RDE900706';
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));

        $this->artisan('desk:fulfil-hardware', [
            'id' => $sourceId,
            '--step' => 'ready',
        ])->assertSuccessful();

        $this->assertSame(
            HardwareFulfilmentState::ReadyForFulfilment,
            HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail()->state,
        );
        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_sku_map_is_owner_approved_and_does_not_guess(): void
    {
        $this->assertSame(8, ChannelSkuMap::query()->count());
        $this->assertTrue(ChannelSkuMap::query()->where('model_id', 951)->where('inventory_product_id', $this->product->id)->exists());
        $this->assertFalse(ChannelSkuMap::query()->where('model_id', 950)->exists());
        $this->assertFalse(ChannelSkuMap::query()->where('model_id', 1010)->exists());
    }

    public function test_serial_allocation_is_locked_and_fail_closed_on_missing_stock(): void
    {
        $sourceId = 'RDE900707';
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));
        $this->isolated->run(identifier: $sourceId, step: 'ready');

        try {
            $this->isolated->run(
                identifier: $sourceId,
                step: 'allocate',
                serials: ['MISSING-SERIAL'],
                actor: $this->actor,
            );
            $this->fail('Missing stock must fail closed.');
        } catch (ValidationException) {
            $this->assertSame(
                HardwareFulfilmentState::ReadyForFulfilment,
                HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail()->state,
            );
        }
    }

    public function test_branch_follows_physical_serial_location(): void
    {
        $sourceId = 'RDE900708';
        $serial = 'SN-RDE900708-001';
        $this->stockAt('MUMBAI', [$serial]);
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));
        $this->isolated->run(identifier: $sourceId, step: 'ready');
        $this->isolated->run(identifier: $sourceId, step: 'allocate', serials: [$serial], actor: $this->actor);

        $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
        $this->assertSame('MUMBAI', $fulfilment->fulfilmentBranch?->code);
    }

    public function test_timeout_search_before_create_and_duplicate_shipment_protection(): void
    {
        $sourceId = 'RDE900709';
        $serial = 'SN-RDE900709-001';
        $this->stockAt('DELHI-RETAIL', [$serial]);
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));
        $this->isolated->run(identifier: $sourceId, step: 'ready');
        $this->isolated->run(identifier: $sourceId, step: 'allocate', serials: [$serial], actor: $this->actor);
        $this->isolated->run(identifier: $sourceId, step: 'invoice', actor: $this->actor);
        $this->selectTestCourier(
            HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail(),
            $this->actor,
        );

        $this->shiprocket->nextCreateMode = 'timeout_accepted';
        try {
            $this->isolated->run(identifier: $sourceId, step: 'ship');
            $this->fail('Timeout must require reconcile.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Reconcile', implode(' ', $exception->errors()['shipping'] ?? []));
        }

        $bound = $this->isolated->run(identifier: $sourceId, step: 'ship');
        $this->assertSame(1, $this->shiprocket->creates);
        $this->assertSame(1, $this->shiprocket->searches);
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated->value, $bound['state']);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_cannot_mark_shipped_without_awb_evidence(): void
    {
        $sourceId = 'RDE900710';
        $serial = 'SN-RDE900710-001';
        $this->stockAt('DELHI-RETAIL', [$serial]);
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));
        $this->isolated->run(identifier: $sourceId, step: 'ready');
        $this->isolated->run(identifier: $sourceId, step: 'allocate', serials: [$serial], actor: $this->actor);
        $this->isolated->run(identifier: $sourceId, step: 'invoice', actor: $this->actor);
        $this->selectTestCourier(
            HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail(),
            $this->actor,
        );
        $this->isolated->run(identifier: $sourceId, step: 'ship');

        $this->expectException(ValidationException::class);
        $this->isolated->run(identifier: $sourceId, step: 'shipped');
    }

    public function test_inbound_callback_is_disabled_by_default_and_rejects_bad_signatures(): void
    {
        $sourceId = 'RDE900711';
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));
        $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();

        $this->postJson('/api/desk/fulfilment-status', [
            'event_id' => 'evt-1',
            'source_id' => $sourceId,
            'hardware_fulfilment_id' => $fulfilment->id,
        ])->assertNotFound();

        config([
            'hardware_fulfilment.callback.inbound_enabled' => true,
            'hardware_fulfilment.callback.secret' => self::CALLBACK_SECRET,
        ]);

        $body = json_encode([
            'event_id' => 'evt-1',
            'source_id' => $sourceId,
            'hardware_fulfilment_id' => $fulfilment->id,
            'state' => HardwareFulfilmentState::Shipped->value,
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/desk/fulfilment-status', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => (string) time(),
            'HTTP_X_DESK_SIGNATURE' => 'deadbeef',
        ], $body)->assertUnauthorized();
    }

    public function test_inbound_callback_is_authenticated_and_idempotent(): void
    {
        $sourceId = 'RDE900712';
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->handoffPayload($sourceId));
        $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
        $fulfilment->forceFill([
            'state' => HardwareFulfilmentState::Shipped,
            'shipped_at' => now(),
        ])->save();

        config([
            'hardware_fulfilment.callback.inbound_enabled' => true,
            'hardware_fulfilment.callback.secret' => self::CALLBACK_SECRET,
        ]);

        $payload = [
            'event_id' => 'evt-idem-1',
            'source_id' => $sourceId,
            'hardware_fulfilment_id' => $fulfilment->id,
            'state' => HardwareFulfilmentState::Shipped->value,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = (new HardwareFulfilmentCallbackSigner(new ChannelIngestAuthenticator))
            ->sign($body, (int) $timestamp)['signature'];

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/api/desk/fulfilment-status', [], [], [], $headers, $body)
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => false]);

        $this->call('POST', '/api/desk/fulfilment-status', [], [], [], $headers, $body)
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => true]);

        $this->assertSame(1, HardwareFulfilmentEvent::query()->where('actor_type', 'box_inbound')->count());
        $this->assertSame(HardwareFulfilmentState::Synced, $fulfilment->fresh()->state);
    }

    public function test_default_bindings_remain_null_when_http_is_not_enabled(): void
    {
        config([
            'shipping.enabled' => true,
            'shipping.provider' => 'shiprocket',
            'shipping.http_enabled' => false,
            'shipping.api_email' => 'ship@example.test',
            'shipping.api_password' => 'secret',
        ]);
        $this->app->forgetInstance(ShiprocketGateway::class);
        $this->app->forgetInstance(BoxFulfilmentCallbackGateway::class);

        $this->assertInstanceOf(NullShiprocketGateway::class, app(ShiprocketGateway::class));
        $this->assertInstanceOf(NullBoxFulfilmentCallbackGateway::class, app(BoxFulfilmentCallbackGateway::class));
        $this->assertFalse((bool) config('hardware_fulfilment.callback.enabled'));
        $this->assertFalse((bool) config('hardware_fulfilment.callback.inbound_enabled'));
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('channel_ingest.auto_issue_invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    private function handoffPayload(
        string $sourceId,
        string $orderedAt = '2026-09-06T10:00:00+05:30',
        string $paymentStatus = 'paid',
    ): array {
        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => $paymentStatus,
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'ordered_at' => $orderedAt,
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
                'state' => 'Madhya Pradesh',
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
    }

    private function mapApprovedSkus(): void
    {
        $maps = [
            951 => $this->product->id,
            1006 => $this->product->id,
            946 => $this->product->id,
            945 => $this->product->id,
            930 => $this->product->id,
            931 => $this->product->id,
            1723 => $this->product->id,
            926 => $this->product->id,
        ];

        foreach ($maps as $modelId => $productId) {
            ChannelSkuMap::query()->firstOrCreate(
                [
                    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                    'model_id' => $modelId,
                ],
                ['inventory_product_id' => $productId],
            );
        }
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
}
