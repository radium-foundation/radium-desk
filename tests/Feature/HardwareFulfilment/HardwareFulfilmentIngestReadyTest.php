<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\HardwareFulfilmentSerial;
use App\Models\HardwareRecoveredFulfilmentAuthorization as AuthorizationRow;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareFulfilmentIngestReadyTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.post_finance_journals' => false,
            'shipping.enabled' => true,
            'shipping.http_enabled' => false,
        ]);
    }

    public function test_eligible_hardware_ingest_advances_ingested_to_ready_through_mark_ready(): void
    {
        $this->signedBoxPost($this->eligibleBoxPayload('RDE910101'))->assertCreated();

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->state);
        $this->assertNotNull($fulfilment->ready_at);
        $this->assertSame(
            ['channel_ingest', 'isolated_ready_for_fulfilment'],
            HardwareFulfilmentEvent::query()->orderBy('id')->pluck('payload')->map(
                static fn (?array $payload): string => (string) ($payload['reason'] ?? '')
            )->all(),
        );
        $this->assertNoDownstreamMutations();
    }

    public function test_missing_order_date_keeps_ingested_and_exposes_blocker(): void
    {
        $payload = $this->eligibleBoxPayload('RDE910102');
        unset($payload['ordered_at']);
        $this->signedBoxPost($payload)->assertCreated();

        $fulfilment = HardwareFulfilment::query()->with('commerceOrder.items')->firstOrFail();
        $this->assertSame(HardwareFulfilmentState::Ingested, $fulfilment->state);
        $this->assertNull($fulfilment->ready_at);
        $this->assertStringContainsString(
            'persisted business order date',
            (string) HardwareFulfilmentEligibility::isolatedTargetBlocker($fulfilment, $fulfilment->commerceOrder),
        );
        $this->assertNoDownstreamMutations();
    }

    public function test_unpaid_commerce_keeps_ingested(): void
    {
        $payload = $this->eligibleBoxPayload('RDE910103');
        $payload['payment_status'] = 'pending';
        $this->signedBoxPost($payload)->assertCreated();

        $fulfilment = HardwareFulfilment::query()->with('commerceOrder.items')->firstOrFail();
        $this->assertSame(HardwareFulfilmentState::Ingested, $fulfilment->state);
        $this->assertStringContainsString(
            'paid commerce order',
            (string) HardwareFulfilmentEligibility::isolatedTargetBlocker($fulfilment, $fulfilment->commerceOrder),
        );
    }

    public function test_unauthorized_frozen_source_does_not_open_or_ready(): void
    {
        $sourceId = HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0];
        $this->signedBoxPost($this->eligibleBoxPayload($sourceId))->assertCreated();

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
        $this->assertTrue(HardwareFulfilmentEligibility::isFrozenForFulfilment($sourceId));
    }

    public function test_authorized_recovered_commerce_uses_existing_mark_ready_gate(): void
    {
        $order = $this->recoveredCommerce('RDE318367', 'CO-000761');
        app(HardwareRecoveredFulfilmentAuthorization::class)->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        $result = app(HardwareFulfilmentIsolatedWorkflowService::class)->run(
            identifier: 'RDE318367',
            step: 'ingest',
        );

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment->value, $result['state']);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->state);
        $this->assertNotNull($fulfilment->ready_at);
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertNoDownstreamMutations();
    }

    public function test_unauthorized_recovered_commerce_remains_blocked(): void
    {
        $this->recoveredCommerce('RDE318391', 'CO-000758');

        try {
            app(HardwareFulfilmentIsolatedWorkflowService::class)->run(
                identifier: 'RDE318391',
                step: 'ingest',
            );
            $this->fail('Unauthorized frozen recovered ingest must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Frozen pending hardware orders',
                collect($exception->errors())->flatten()->first() ?? '',
            );
        }

        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, AuthorizationRow::query()->count());
    }

    public function test_duplicate_eligible_ingest_does_not_duplicate_hf_or_ready_event(): void
    {
        $payload = $this->eligibleBoxPayload('RDE910104');
        $this->signedBoxPost($payload)->assertCreated();
        $this->signedBoxPost($payload)->assertOk()->assertJsonPath('duplicate', true);

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->state);
        $this->assertSame(
            1,
            HardwareFulfilmentEvent::query()
                ->where('to_state', HardwareFulfilmentState::ReadyForFulfilment)
                ->count(),
        );
        $this->assertNoDownstreamMutations();
    }

    public function test_already_ready_ingest_retry_leaves_serials_invoices_and_shipments_untouched(): void
    {
        $payload = $this->eligibleBoxPayload('RDE910105');
        $this->signedBoxPost($payload)->assertCreated();
        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $readyAt = $fulfilment->ready_at?->toDateTimeString();

        $again = app(HardwareFulfilmentWorkflowService::class)->markReady($fulfilment);
        $this->signedBoxPost($payload)->assertOk()->assertJsonPath('duplicate', true);

        $fresh = $fulfilment->fresh();
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $again->state);
        $this->assertSame($readyAt, $fresh?->ready_at?->toDateTimeString());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(
            1,
            HardwareFulfilmentEvent::query()
                ->where('to_state', HardwareFulfilmentState::ReadyForFulfilment)
                ->count(),
        );
        $this->assertNoDownstreamMutations();
    }

    public function test_ingested_serial_allocation_remains_rejected(): void
    {
        $payload = $this->eligibleBoxPayload('RDE910106');
        unset($payload['ordered_at']);
        $this->signedBoxPost($payload)->assertCreated();
        $fulfilment = HardwareFulfilment::query()->firstOrFail();

        $this->expectException(ValidationException::class);
        app(HardwareFulfilmentWorkflowService::class)->assertCanAllocateSerials($fulfilment);
    }

    public function test_http_ready_action_uses_mark_ready_for_existing_ingested(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $fulfilment = $this->persistedIngested('RDE910107', eligible: true);

        $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.ready.store', $fulfilment), [
                'serials' => ['SN-SHOULD-NOT'],
            ])
            ->assertSessionHasErrors('serials');

        $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.ready.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertNoDownstreamMutations();
    }

    public function test_dashboard_ingested_never_offers_allocate_serial(): void
    {
        $eligible = $this->persistedIngested('RDE910108', eligible: true);
        $blocked = $this->persistedIngested('RDE910109', eligible: false);
        $ready = $this->persistedIngested('RDE910110', eligible: true);
        $ready->forceFill([
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'ready_at' => now(),
        ])->save();

        $classifier = app(HardwareFulfilmentOperationalClassifier::class);
        $inspect = app(HardwareShipmentEligibility::class);

        $eligibleRow = $classifier->fromFulfilment($eligible->fresh(['commerceOrder.items']), $inspect->inspect($eligible));
        $blockedRow = $classifier->fromFulfilment($blocked->fresh(['commerceOrder.items']), $inspect->inspect($blocked));
        $readyRow = $classifier->fromFulfilment($ready->fresh(['commerceOrder.items']), $inspect->inspect($ready));

        $this->assertSame('Ready for Fulfilment', $eligibleRow->nextAction);
        $this->assertSame(HardwareFulfilmentOperationalStage::AwaitingFulfilment, $eligibleRow->stage);
        $this->assertNotSame('Allocate Serial', $eligibleRow->nextAction);
        $this->assertSame('View', $blockedRow->nextAction);
        $this->assertNotSame('Allocate Serial', $blockedRow->nextAction);
        $this->assertNotNull($blockedRow->blocker);
        $this->assertSame('Allocate Serial', $readyRow->nextAction);
        $this->assertSame(HardwareFulfilmentOperationalStage::AwaitingSerial, $readyRow->stage);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->actingAs($admin)
            ->get(route('inventory.hardware-fulfilments.action-dialog', $eligible))
            ->assertOk()
            ->assertSee('Ready for Fulfilment')
            ->assertDontSee('Allocate Serial');
    }

    public function test_channel_ingest_still_refuses_authorized_frozen_source(): void
    {
        $order = $this->recoveredCommerce('RDE318388', 'CO-000755');
        app(HardwareRecoveredFulfilmentAuthorization::class)->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        $request = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RadiumBoxCom,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RDE318388',
            lines: [new ChannelOrderLineDraft(
                description: 'Radium Box UGR',
                qty: 1,
                unitPrice: 1999,
                sku: '1723',
                shippingLineKind: HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                requiresShipping: true,
                modelId: 1723,
            )],
            paymentStatus: 'paid',
            currency: 'INR',
        );

        $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId('RDE318388'));
        $this->assertFalse(HardwareFulfilmentEligibility::isFrozenForFulfilment('RDE318388', $order));
        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
    }

    public function test_hold_and_blocked_until_authorized_stay_ingested(): void
    {
        $hold = $this->persistedIngested(HardwareFulfilmentEligibility::HOLD_SOURCE_IDS[0], eligible: true);
        $blocked = $this->persistedIngested(
            HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0],
            eligible: true,
        );

        $holdReady = app(HardwareFulfilmentWorkflowService::class);
        try {
            $holdReady->markReady($hold);
            $this->fail('HOLD must not become ready.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Owner-HOLD', collect($exception->errors())->flatten()->first() ?? '');
        }

        try {
            $holdReady->markReady($blocked);
            $this->fail('RDE318400 must not become ready.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not authorized', collect($exception->errors())->flatten()->first() ?? '');
        }

        $this->assertSame(HardwareFulfilmentState::Ingested, $hold->fresh()->state);
        $this->assertSame(HardwareFulfilmentState::Ingested, $blocked->fresh()->state);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedBoxPost(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::BOX_SECRET),
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function eligibleBoxPayload(string $sourceId): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'ordered_at' => '2026-09-06T10:00:00+05:30',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 951,
            ]],
        ];
    }

    private function recoveredCommerce(string $sourceId, string $orderNo): CommerceOrder
    {
        Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'Recovered hardware '.$sourceId,
            'status' => 'active',
            'created_by' => User::factory()->create(['is_active' => true])->id,
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'payment_amount' => 1000,
        ]);

        $order = CommerceOrder::query()->create([
            'order_no' => $orderNo,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'ordered_at' => '2026-09-06 10:00:00',
            'paid_at' => '2026-09-06 10:05:00',
            'received_at' => now(),
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $order->id,
            'line_no' => 1,
            'sku' => '946',
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'model_id' => 946,
            'description' => 'Line '.$sourceId,
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 1000,
            'gst_percentage' => 18,
            'taxable_value' => 847.46,
            'tax_total' => 152.54,
            'line_total' => 1000,
        ]);

        return $order->fresh(['items']);
    }

    private function persistedIngested(string $sourceId, bool $eligible): HardwareFulfilment
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'ordered_at' => $eligible ? '2026-09-06 10:00:00' : null,
            'received_at' => now(),
        ]);

        if ($eligible) {
            CommerceOrderItem::query()->create([
                'commerce_order_id' => $order->id,
                'line_no' => 1,
                'sku' => '951',
                'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                'requires_shipping' => true,
                'model_id' => 951,
                'description' => 'MSO1300',
                'qty' => 1,
                'unit_price' => 3049,
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049,
            ]);
        }

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $order->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $order->idempotency_key,
            'state' => HardwareFulfilmentState::Ingested,
            'ingested_at' => now(),
        ]);
    }

    private function assertNoDownstreamMutations(): void
    {
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
    }
}
