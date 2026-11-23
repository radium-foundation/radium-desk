<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\PreShipmentCancellation\ClosePreShipmentCancellationIncidentJob;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class PreShipmentCancellationOperatorHttpTest extends TestCase
{
    use RefreshDatabase;

    private FakeShiprocketGateway $shiprocket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        foreach ([
            'preship_cancellations.submit',
            'preship_cancellations.view',
            'preship_cancellations.central',
            'preship_cancellations.review',
            'preship_cancellations.approve',
            'preship_cancellations.reject',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        config(['pre_shipment_cancellation.enabled' => true]);
        $this->shiprocket = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->shiprocket);
    }

    /**
     * @return array{0: User, 1: Order, 2: CommerceOrder, 3: ?HardwareFulfilment}
     */
    private function paidHardwareFixtures(
        string $orderId = 'RBP9100001',
        ?HardwareFulfilmentState $hfState = null,
        float $walletTender = 0,
    ): array {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $actor->givePermissionTo([
            'preship_cancellations.submit',
            'preship_cancellations.view',
            'preship_cancellations.central',
            'preship_cancellations.review',
            'preship_cancellations.approve',
            'preship_cancellations.reject',
        ]);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'product_name' => 'Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 2000,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'created_by' => $actor->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'psc-http-'.$order->id,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => 2000,
            'wallet_tender_amount' => $walletTender > 0 ? $walletTender : null,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $fulfilment = null;
        if ($hfState !== null) {
            $branch = InventoryBranch::query()->create([
                'code' => 'BR-HTTP-'.random_int(100, 999),
                'name' => 'HTTP Branch',
                'is_active' => true,
            ]);
            $fulfilment = HardwareFulfilment::query()->create([
                'commerce_order_id' => $commerce->id,
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $orderId,
                'idempotency_key' => $commerce->idempotency_key,
                'state' => $hfState,
                'support_order_id' => $order->id,
                'fulfilment_branch_id' => $branch->id,
                'ingested_at' => now(),
            ]);
        }

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-HTTP-'.random_int(1000, 9999),
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Hardware',
            'description' => 'Awaiting serial',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        return [$actor, $order, $commerce, $fulfilment];
    }

    public function test_flag_off_hides_create_route_with_404(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        [$actor, $order] = array_slice($this->paidHardwareFixtures(), 0, 2);

        $this->actingAs($actor)
            ->get(route('orders.pre-shipment-cancellations.create', $order))
            ->assertNotFound();
    }

    public function test_unauthorized_user_receives_403_on_create(): void
    {
        [$actor, $order] = array_slice($this->paidHardwareFixtures(), 0, 2);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('orders.pre-shipment-cancellations.create', $order))
            ->assertForbidden();
    }

    public function test_state_a_submit_via_http(): void
    {
        [$actor, $order] = array_slice($this->paidHardwareFixtures(), 0, 2);

        $response = $this->actingAs($actor)
            ->post(route('orders.pre-shipment-cancellations.store', $order), [
                'cancellation_reason' => 'Customer requested cancel before shipment.',
                'idempotency_key' => 'psc-http-a-'.$order->id,
            ]);

        $case = PreShipmentCancellationRequest::query()->firstOrFail();
        $response->assertRedirect(route('pre-shipment-cancellations.show', $case));
        $this->assertSame(0, $this->shiprocket->cancels);
    }

    public function test_state_b_submit_via_http(): void
    {
        [$actor, $order, , $fulfilment] = $this->paidHardwareFixtures(hfState: HardwareFulfilmentState::ReadyForFulfilment);

        $this->actingAs($actor)
            ->post(route('orders.pre-shipment-cancellations.store', $order), [
                'cancellation_reason' => 'Cancel ingested hardware before ship.',
                'idempotency_key' => 'psc-http-b-'.$order->id,
            ])
            ->assertRedirect();

        $this->assertSame(HardwareFulfilmentState::CancelledPreShipment, $fulfilment->fresh()->state);
        $this->assertSame(0, $this->shiprocket->cancels);
    }

    public function test_state_c_http_rejected_when_serials_allocated(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(hfState: HardwareFulfilmentState::SerialsAllocated);

        $this->actingAs($actor)
            ->get(route('orders.pre-shipment-cancellations.create', $order))
            ->assertOk()
            ->assertSee('Not eligible')
            ->assertSee('fulfilment_beyond_preship_scope');
    }

    public function test_split_tender_show_displays_finance_notice(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(walletTender: 500);

        $this->actingAs($actor)
            ->post(route('orders.pre-shipment-cancellations.store', $order), [
                'cancellation_reason' => 'Split tender manual finance path.',
                'idempotency_key' => 'psc-split-http-'.$order->id,
            ]);

        $case = PreShipmentCancellationRequest::query()->firstOrFail();
        $this->assertTrue($case->split_tender);

        $this->actingAs($actor)
            ->get(route('pre-shipment-cancellations.show', $case))
            ->assertOk()
            ->assertSee('Split tender')
            ->assertSee('manual Finance');
    }

    public function test_duplicate_idempotency_via_http(): void
    {
        [$actor, $order] = array_slice($this->paidHardwareFixtures(orderId: 'RDE9100002'), 0, 2);
        $key = 'psc-dup-http-'.$order->id;

        $this->actingAs($actor)->post(route('orders.pre-shipment-cancellations.store', $order), [
            'cancellation_reason' => 'Duplicate idempotency HTTP test.',
            'idempotency_key' => $key,
        ]);
        $this->actingAs($actor)->post(route('orders.pre-shipment-cancellations.store', $order), [
            'cancellation_reason' => 'Duplicate idempotency HTTP test.',
            'idempotency_key' => $key,
        ]);

        $this->assertSame(1, PreShipmentCancellationRequest::query()->count());
    }

    public function test_central_queue_requires_central_permission(): void
    {
        [$actor] = array_slice($this->paidHardwareFixtures(), 0, 1);
        $outsider = User::factory()->create();

        $this->actingAs($actor)->get(route('pre-shipment-cancellations.central-queue'))->assertOk();
        $this->actingAs($outsider)->get(route('pre-shipment-cancellations.central-queue'))->assertForbidden();
    }

    public function test_finance_approve_via_http_for_split_tender(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(walletTender: 300);

        $this->actingAs($actor)->post(route('orders.pre-shipment-cancellations.store', $order), [
            'cancellation_reason' => 'Approve split tender in finance queue.',
            'idempotency_key' => 'psc-fin-http-'.$order->id,
        ]);

        $case = PreShipmentCancellationRequest::query()->firstOrFail();
        $this->assertSame(PreShipmentCancellationStatus::FinanceReview, $case->status);

        $this->actingAs($actor)
            ->post(route('pre-shipment-cancellations.finance-approve', $case))
            ->assertRedirect(route('pre-shipment-cancellations.show', $case));

        $this->assertSame(PreShipmentCancellationStatus::FinanceApproved, $case->fresh()->status);
    }

    public function test_retry_incident_dispatches_job(): void
    {
        Bus::fake([ClosePreShipmentCancellationIncidentJob::class]);
        [$actor, $order] = array_slice($this->paidHardwareFixtures(), 0, 2);

        $this->actingAs($actor)->post(route('orders.pre-shipment-cancellations.store', $order), [
            'cancellation_reason' => 'Queue incident retry from operator UI.',
            'idempotency_key' => 'psc-retry-http-'.$order->id,
        ]);

        $case = PreShipmentCancellationRequest::query()->firstOrFail();

        $this->actingAs($actor)
            ->post(route('pre-shipment-cancellations.retry-incident', $case))
            ->assertRedirect();

        Bus::assertDispatched(ClosePreShipmentCancellationIncidentJob::class);
    }
}
