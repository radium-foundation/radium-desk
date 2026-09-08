<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\HardwareFulfilment\BoxFulfilmentCallbackGateway;
use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackOutboxWriter;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackPayload;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackProjection;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackSigner;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Services\HardwareFulfilment\NullBoxFulfilmentCallbackGateway;
use App\Services\Inventory\InventoryStockService;
use App\Services\Outbox\OutboxProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\HardwareFulfilment\Support\FakeBoxFulfilmentCallbackGateway;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentP6CallbackTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private const CALLBACK_SECRET = 'test-desk-callback-secret';

    private FakeBoxFulfilmentCallbackGateway $fake;

    private HardwareFulfilmentWorkflowService $workflow;

    private User $actor;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        $this->fake = new FakeBoxFulfilmentCallbackGateway;
        $this->app->instance(BoxFulfilmentCallbackGateway::class, $this->fake);
        $this->app->instance(ShiprocketGateway::class, new FakeShiprocketGateway);
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.callback.enabled' => false,
            'hardware_fulfilment.callback.url' => '',
            'hardware_fulfilment.callback.secret' => '',
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

        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-MSO-CB',
            'name' => 'Desk MSO callback test',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_callback_event_is_created_after_authoritative_commit_without_http(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE900601');

        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->state);
        $this->assertSame(1, $this->callbackOutbox()->count());
        $this->assertSame(0, $this->fake->sends);
        $this->assertSame(OutboxEventStatus::Pending, $this->callbackOutbox()->first()->status);
    }

    public function test_successful_delivery_happens_outside_the_database_transaction(): void
    {
        $this->enableCallbackDelivery();
        $fulfilment = $this->allocatedFulfilment('RDE900602');
        $this->assertSame(0, $this->fake->sends);

        $this->drainCallbackOutbox();

        $this->assertSame(1, $this->fake->sends);
        $this->assertSame(DB::transactionLevel(), $this->fake->transactionLevelAtLastSend);
        $this->assertSame(OutboxEventStatus::Completed, $this->callbackOutbox()->first()->status);
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->fresh()->state);
    }

    public function test_duplicate_operator_execution_does_not_create_duplicate_callback_events(): void
    {
        $fulfilment = $this->allocatedFulfilment('RDE900603');
        $existing = $this->callbackOutbox()->firstOrFail();
        $event = $fulfilment->events()->where('to_state', HardwareFulfilmentState::SerialsAllocated)->firstOrFail();

        $again = app(HardwareFulfilmentCallbackOutboxWriter::class)->enqueue($fulfilment, $event);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::SerialsAllocated);

        $this->assertSame(1, $this->callbackOutbox()->count());
        $this->assertSame($existing->id, $again?->id);
        $this->assertSame($existing->payload['event_id'], $this->callbackOutbox()->first()->payload['event_id']);
    }

    public function test_timeout_and_5xx_retries_reuse_the_same_event_identity(): void
    {
        $this->enableCallbackDelivery();
        $this->allocatedFulfilment('RDE900604');
        $eventId = $this->callbackOutbox()->first()->payload['event_id'];

        $this->fake->mode = 'timeout';
        $this->drainCallbackOutbox();
        $this->assertSame(1, $this->fake->sends);
        $this->assertSame(OutboxEventStatus::Pending, $this->callbackOutbox()->first()->status);
        $this->assertSame($eventId, $this->callbackOutbox()->first()->payload['event_id']);

        $this->fake->mode = 'retryable_5xx';
        $this->drainCallbackOutbox();
        $this->assertSame(2, $this->fake->sends);
        $this->assertSame($eventId, $this->callbackOutbox()->first()->payload['event_id']);
        $this->assertSame($eventId, $this->fake->calls[0]->eventId);
        $this->assertSame($eventId, $this->fake->calls[1]->eventId);

        $this->fake->mode = 'accepted';
        $this->drainCallbackOutbox();
        $this->assertSame(3, $this->fake->sends);
        $this->assertSame(OutboxEventStatus::Completed, $this->callbackOutbox()->first()->status);
        $this->assertSame($eventId, $this->fake->calls[2]->eventId);
    }

    public function test_non_retryable_4xx_fails_closed(): void
    {
        $this->enableCallbackDelivery();
        $this->allocatedFulfilment('RDE900605');
        $eventId = $this->callbackOutbox()->first()->payload['event_id'];

        $this->fake->mode = 'non_retryable_4xx';
        $this->drainCallbackOutbox();
        $this->drainCallbackOutbox();

        $this->assertSame(1, $this->fake->sends);
        $this->assertSame(OutboxEventStatus::Failed, $this->callbackOutbox()->first()->status);
        $this->assertSame($eventId, $this->callbackOutbox()->first()->payload['event_id']);
    }

    public function test_malformed_invalid_and_valid_hmac_and_stale_replay(): void
    {
        config([
            'hardware_fulfilment.callback.secret' => self::CALLBACK_SECRET,
            'hardware_fulfilment.callback.replay_window_seconds' => 300,
        ]);
        $signer = app(HardwareFulfilmentCallbackSigner::class);
        $body = HardwareFulfilmentCallbackPayload::encode(['event_id' => 'evt-1']);

        $malformed = Request::create('/callback', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
        $this->assertFalse($signer->verify($malformed)['ok']);

        $signed = $signer->sign($body);
        $invalid = Request::create('/callback', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => $signed['channel'],
            'HTTP_X_DESK_TIMESTAMP' => $signed['timestamp'],
            'HTTP_X_DESK_SIGNATURE' => str_repeat('0', 64),
        ], content: $body);
        $this->assertFalse($signer->verify($invalid)['ok']);
        $this->assertFalse($signer->verify($invalid)['replay']);

        $valid = Request::create('/callback', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => $signed['channel'],
            'HTTP_X_DESK_TIMESTAMP' => $signed['timestamp'],
            'HTTP_X_DESK_SIGNATURE' => $signed['signature'],
        ], content: $body);
        $this->assertTrue($signer->verify($valid)['ok']);

        $staleSigned = $signer->sign($body, time() - 400);
        $stale = Request::create('/callback', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => $staleSigned['channel'],
            'HTTP_X_DESK_TIMESTAMP' => $staleSigned['timestamp'],
            'HTTP_X_DESK_SIGNATURE' => $staleSigned['signature'],
        ], content: $body);
        $verified = $signer->verify($stale);
        $this->assertFalse($verified['ok']);
        $this->assertTrue($verified['replay']);
    }

    public function test_out_of_order_event_cannot_regress_newer_state(): void
    {
        $projection = new HardwareFulfilmentCallbackProjection;
        $newer = [
            'source_id' => 'RDE900606',
            'sequence' => 8,
            'state' => HardwareFulfilmentState::AwbAssigned->value,
            'event_id' => 'evt-newer',
        ];
        $older = [
            'source_id' => 'RDE900606',
            'sequence' => 4,
            'state' => HardwareFulfilmentState::SerialsAllocated->value,
            'event_id' => 'evt-older',
        ];

        $this->assertTrue($projection->apply($newer));
        $this->assertFalse($projection->apply($older));
        $this->assertTrue($projection->apply($newer));
        $this->assertSame(HardwareFulfilmentState::AwbAssigned->value, $projection->stateFor('RDE900606'));
        $this->assertSame(8, $projection->sequenceFor('RDE900606'));
    }

    public function test_invoice_serial_shipment_and_awb_fields_are_authoritative(): void
    {
        $this->enableCallbackDelivery();
        $fulfilment = $this->invoicedFulfilment('RDE900607');
        $invoice = StatutoryInvoice::query()->where('source_id', 'RDE900607')->firstOrFail();

        $this->drainCallbackOutbox();
        $invoiceCall = $this->lastCallForState(HardwareFulfilmentState::InvoiceIssued);
        $this->assertSame($invoice->invoice_number, $invoiceCall->payload['invoice']['number']);
        $this->assertSame(['SN-RDE900607-001'], $invoiceCall->payload['serials']);
        $this->assertArrayNotHasKey('shipment', $invoiceCall->payload);
        $this->assertArrayNotHasKey('document_url', $invoiceCall->payload);
        $this->assertArrayNotHasKey('pdf_path', $invoiceCall->payload);

        app(HardwareShipmentService::class)->createShipment($fulfilment->fresh(['commerceOrder.items']));
        $this->drainCallbackOutbox();
        $shipmentCall = $this->lastCallForState(HardwareFulfilmentState::ShipmentCreated);
        $this->assertSame('HW-RDE900607', $shipmentCall->payload['shipment']['shipment_no']);
        $this->assertSame($fulfilment->fresh()->provider_shipment_id, $shipmentCall->payload['shipment']['provider_shipment_id']);
        $this->assertArrayNotHasKey('awb', $shipmentCall->payload['shipment']);
        $this->assertSame(HardwareFulfilmentState::ShipmentCreated->value, $shipmentCall->payload['state']);
        $this->assertNotSame(HardwareFulfilmentState::Shipped->value, $shipmentCall->payload['state']);

        app(HardwareShipmentService::class)->assignAwb($fulfilment->fresh());
        $this->drainCallbackOutbox();
        $awbCall = $this->lastCallForState(HardwareFulfilmentState::AwbAssigned);
        $this->assertSame($fulfilment->fresh()->awb, $awbCall->payload['shipment']['awb']);
        $this->assertNotSame('', (string) $awbCall->payload['shipment']['awb']);
        $this->assertNotSame(HardwareFulfilmentState::Shipped->value, $awbCall->payload['state']);
        $this->assertSame(0, $this->callbackOutbox()->where('idempotency_key', 'like', '%:shipped')->count());
    }

    public function test_shipped_is_emitted_only_after_verified_shipped_state_and_ack_marks_synced(): void
    {
        $this->enableCallbackDelivery();
        $fulfilment = $this->awbFulfilment('RDE900608');
        $this->assertSame(0, $this->callbackOutbox()->where('idempotency_key', 'like', '%:shipped')->count());

        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::Shipped);
        $this->assertSame(HardwareFulfilmentState::Shipped, $fulfilment->fresh()->state);

        $this->drainCallbackOutbox();
        $shipped = $this->lastCallForState(HardwareFulfilmentState::Shipped);
        $this->assertSame(HardwareFulfilmentState::Shipped->value, $shipped->payload['state']);
        $this->assertSame(HardwareFulfilmentState::Synced, $fulfilment->fresh()->state);
        $this->assertSame(0, $this->callbackOutbox()->where('idempotency_key', 'like', '%:synced')->count());
    }

    public function test_disabled_or_null_gateway_does_not_call_http_and_production_bind_is_null(): void
    {
        $this->allocatedFulfilment('RDE900609');
        $this->drainCallbackOutbox();

        $this->assertSame(0, $this->fake->sends);
        $this->assertSame(OutboxEventStatus::Completed, $this->callbackOutbox()->first()->status);
        $this->assertFalse((bool) config('hardware_fulfilment.callback.enabled'));
        $this->assertSame('', (string) config('hardware_fulfilment.callback.url'));
        $this->assertSame('', (string) config('hardware_fulfilment.callback.secret'));

        $this->app->forgetInstance(BoxFulfilmentCallbackGateway::class);
        $this->assertInstanceOf(NullBoxFulfilmentCallbackGateway::class, $this->app->make(BoxFulfilmentCallbackGateway::class));
    }

    public function test_payload_has_no_secrets_and_box_cannot_mutate_desk_state(): void
    {
        $this->enableCallbackDelivery();
        Log::spy();
        $fulfilment = $this->allocatedFulfilment('RDE900610');
        $this->drainCallbackOutbox();

        $payload = $this->fake->calls[0]->payload;
        $raw = $this->fake->calls[0]->rawBody;
        $this->assertSame($payload['identity'], 'statutory:radiumbox_com:commerce_order:RDE900610');
        $this->assertMatchesRegularExpression('/^[0-9a-fA-F-]{36}$/', $payload['event_id']);
        $this->assertStringNotContainsString(self::CALLBACK_SECRET, $raw);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertArrayNotHasKey('cashfree_payment_id', $payload);
        $this->assertArrayNotHasKey('payment_reference', $payload);

        $stateBefore = $fulfilment->fresh()->state;
        $this->postJson('/api/desk/fulfilment-status', [
            'source_id' => 'RDE900610',
            'state' => HardwareFulfilmentState::Synced->value,
            'invoice' => ['number' => 'INV-FAKE'],
        ])->assertNotFound();

        $this->assertSame($stateBefore, $fulfilment->fresh()->state);
        $this->assertSame(0, StatutoryInvoice::query()->where('invoice_number', 'INV-FAKE')->count());
    }

    public function test_frozen_pending_orders_are_not_callback_targets(): void
    {
        foreach (HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS as $sourceId) {
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
        }

        $this->assertSame(0, CommerceOrder::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, HardwareFulfilment::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, $this->callbackOutbox()->count());
        $this->assertSame(0, $this->fake->sends);
    }

    private function enableCallbackDelivery(): void
    {
        config([
            'hardware_fulfilment.callback.enabled' => true,
            'hardware_fulfilment.callback.url' => 'https://radiumbox.test/api/desk/fulfilment-status',
            'hardware_fulfilment.callback.secret' => self::CALLBACK_SECRET,
            'hardware_fulfilment.callback.replay_window_seconds' => 300,
        ]);
    }

    private function drainCallbackOutbox(): void
    {
        OutboxEvent::query()
            ->where('event_type', HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE)
            ->where('status', OutboxEventStatus::Pending)
            ->update(['available_at' => now()->subSecond()]);

        app(OutboxProcessorService::class)->process();
    }

    private function callbackOutbox()
    {
        return OutboxEvent::query()->where('event_type', HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE);
    }

    private function lastCallForState(HardwareFulfilmentState $state): object
    {
        $matches = array_values(array_filter(
            $this->fake->calls,
            static fn ($call): bool => ($call->payload['state'] ?? null) === $state->value,
        ));

        $this->assertNotSame([], $matches, 'Expected a callback for '.$state->value);

        return $matches[array_key_last($matches)];
    }

    private function awbFulfilment(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->invoicedFulfilment($sourceId);
        app(HardwareShipmentService::class)->createShipment($fulfilment->fresh(['commerceOrder.items']));
        app(HardwareShipmentService::class)->assignAwb($fulfilment->fresh());

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function invoicedFulfilment(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->allocatedFulfilment($sourceId);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        return $this->selectTestCourier($fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment, $this->actor);
    }

    private function allocatedFulfilment(string $sourceId): HardwareFulfilment
    {
        $this->mapModel(951);
        $fulfilment = $this->ingestHardware($sourceId);
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => 'DELHI-RETAIL'],
            ['name' => 'DELHI-RETAIL', 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $branch,
            [sprintf('SN-%s-001', $sourceId)],
            $this->actor,
        );
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment->fresh(),
            [sprintf('SN-%s-001', $sourceId)],
            $this->actor,
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
