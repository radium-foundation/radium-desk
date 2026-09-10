<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareAwaitingFulfilmentQueue;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\Shipping\NullShiprocketGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HardwareFulfilmentAwaitingQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Http::fake();
        Http::preventStrayRequests();
        config([
            'shipping.enabled' => false,
            'shipping.provider' => 'none',
            'shipping.http_enabled' => false,
        ]);

        $this->creator = User::factory()->create(['is_active' => true]);
        $this->operator = User::factory()->create(['is_active' => true]);
        $this->operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_review_candidate_appears_in_awaiting_queue_and_existing_fulfilment_does_not(): void
    {
        $candidate = $this->deskOrder('RDE961001', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->fulfilmentFor('RDE961002');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'awaiting']))
            ->assertOk()
            ->assertSee('Awaiting Fulfilment')
            ->assertSee('Open Fulfilments')
            ->assertSee('Do not create fulfilments from this page.')
            ->assertSee('RDE961001')
            ->assertSee(route('orders.show', $candidate), false)
            ->assertSee(route('dashboard.orders.customer-360', $candidate), false)
            ->assertDontSee('RDE961002')
            ->assertDontSee('Create fulfilment')
            ->assertDontSee('Initialize fulfilment')
            ->assertDontSee('Create Shipment')
            ->assertDontSee('/inventory/shipments');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'open']))
            ->assertOk()
            ->assertSee('RDE961002')
            ->assertDontSee('RDE961001')
            ->assertSee('This list shows only Hardware Fulfilment records');

        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        $this->assertInstanceOf(NullShiprocketGateway::class, app(ShiprocketGateway::class));
        Http::assertNothingSent();
    }

    public function test_rin_frozen_hold_blocked_unpaid_historical_completed_and_non_rde_are_excluded_from_review(): void
    {
        $this->deskOrder('RIN961010', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0], [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder(HardwareFulfilmentEligibility::HOLD_SOURCE_IDS[0], [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE961011', [
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE961012', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-01 10:00:00',
        ]);
        $this->deskOrder('RDE961013', [
            'cashfree_payment_id' => 'paid',
            'serial_number' => '10500013',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RD961014', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $visible = $this->deskOrder('RDE961015', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $html = $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'awaiting']))
            ->assertOk()
            ->assertSee('RDE961015')
            ->assertDontSee('RIN961010')
            ->assertDontSee(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0])
            ->assertDontSee(HardwareFulfilmentEligibility::HOLD_SOURCE_IDS[0])
            ->assertDontSee('RDE961011')
            ->assertDontSee('RDE961012')
            ->assertDontSee('RDE961013')
            ->assertDontSee('RD961014')
            ->getContent();

        $this->assertStringContainsString((string) $visible->order_id, $html);
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        Http::assertNothingSent();
    }

    public function test_excluded_and_historical_filters_surface_the_matching_reason_only(): void
    {
        $frozen = HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0];
        $this->deskOrder($frozen, [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE961020', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-01 10:00:00',
        ]);
        $this->deskOrder('RDE961021', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'awaiting',
                'awaiting_reason' => 'excluded',
            ]))
            ->assertOk()
            ->assertSee($frozen)
            ->assertSee('Frozen')
            ->assertDontSee('RDE961020')
            ->assertDontSee('RDE961021');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'awaiting',
                'awaiting_reason' => 'historical',
            ]))
            ->assertOk()
            ->assertSee('RDE961020')
            ->assertDontSee($frozen)
            ->assertDontSee('RDE961021');
    }

    public function test_review_cutoff_uses_ist_wall_clock_against_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 17:17:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE));

        $this->deskOrder('RDE961040', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-05 00:00:00',
        ]);
        $this->deskOrder('RDE961041', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-04 23:59:59',
        ]);
        $this->deskOrder('RDE961042', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-05 00:00:01',
        ]);
        $this->deskOrder('RDE961043', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-04 18:45:00',
        ]);
        $this->deskOrder('RDE961044', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-08 16:30:00',
        ]);
        $utcCutoffInstant = Carbon::parse('2026-09-04 18:30:00', 'UTC');
        $this->assertSame(
            '2026-09-05 00:00:00',
            HardwareFulfilmentEligibility::createdAtSqlBound($utcCutoffInstant),
        );
        $this->deskOrder('RDE961045', [
            'cashfree_payment_id' => 'paid',
            'created_at' => HardwareFulfilmentEligibility::createdAtSqlBound($utcCutoffInstant),
        ]);
        $this->deskOrder('RDE961046', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-01 10:00:00',
        ]);
        $this->deskOrder(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0], [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE255714', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RIN961047', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-08 16:00:00',
        ]);
        $this->fulfilmentFor('RDE318421');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'awaiting']))
            ->assertOk()
            ->assertSee('RDE961040')
            ->assertSee('RDE961042')
            ->assertSee('RDE961044')
            ->assertSee('RDE961045')
            ->assertDontSee('RDE961041')
            ->assertDontSee('RDE961043')
            ->assertDontSee('RDE961046')
            ->assertDontSee(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0])
            ->assertDontSee('RDE255714')
            ->assertDontSee('RIN961047')
            ->assertDontSee('RDE318421')
            ->assertDontSee('Create fulfilment');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'awaiting',
                'awaiting_reason' => HardwareAwaitingFulfilmentQueue::FILTER_HISTORICAL,
            ]))
            ->assertOk()
            ->assertSee('RDE961041')
            ->assertSee('RDE961043')
            ->assertSee('RDE961046')
            ->assertDontSee('RDE961040')
            ->assertDontSee('RDE961044')
            ->assertDontSee('RDE318421');

        $this->assertSame(1, HardwareFulfilment::query()->where('source_id', 'RDE318421')->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        Http::assertNothingSent();
    }

    public function test_unauthorized_user_cannot_open_awaiting_queue(): void
    {
        $this->deskOrder('RDE961030', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'awaiting']))
            ->assertForbidden();

        $this->assertSame(0, HardwareFulfilment::query()->count());
        Http::assertNothingSent();
    }

    public function test_awaiting_get_does_not_create_fulfilment_or_shipment(): void
    {
        $before = HardwareFulfilment::query()->count();

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'awaiting']))
            ->assertOk();

        $this->assertSame($before, HardwareFulfilment::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function deskOrder(string $orderId, array $overrides = []): Order
    {
        if (filled($overrides['cashfree_payment_id'] ?? null)) {
            $overrides['cashfree_payment_id'] = 'cf_'.$orderId;
        }

        $order = Order::query()->create(array_merge([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => $this->creator->id,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $order->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $order->fresh();
    }

    private function fulfilmentFor(string $orderId): HardwareFulfilment
    {
        $order = $this->deskOrder($orderId, [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$orderId,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::InvoiceIssued,
            'support_order_id' => $order->id,
            'ingested_at' => now(),
        ]);
    }
}
