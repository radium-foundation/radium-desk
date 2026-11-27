<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\PreShipmentEligibilityFailureCode;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\PreShipmentCancellation\ClosePreShipmentCancellationIncidentJob;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationSubmitService;
use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadata;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreShipmentCancellationHardwareDashboardModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        foreach ([
            'preship_cancellations.submit',
            'preship_cancellations.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * @return array{0: User, 1: Order, 2: CommerceOrder, 3: ?HardwareFulfilment}
     */
    private function paidHardwareFixtures(
        string $orderId = 'RBP9101001',
        ?HardwareFulfilmentState $hfState = null,
        float $walletTender = 0,
    ): array {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $actor->givePermissionTo(['preship_cancellations.submit', 'preship_cancellations.view']);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'product_name' => 'Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 2000 - $walletTender,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'created_by' => $actor->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'psc-hw-'.$order->id,
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
                'code' => 'BR-HW-'.random_int(100, 999),
                'name' => 'HW Branch',
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
            'reference_no' => 'INC-HW-'.random_int(1000, 9999),
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Hardware',
            'description' => 'Awaiting serial',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        return [$actor, $order, $commerce, $fulfilment];
    }

    public function test_hardware_dashboard_hides_cancel_action_when_feature_disabled(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        [$actor] = array_slice($this->paidHardwareFixtures(), 0, 1);

        $html = $this->actingAs($actor)
            ->get(route('dashboard', $this->dashboardQuery(['queue' => 'hardware'])))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-hardware-preship-cancel-enabled', $html);
        $this->assertStringNotContainsString('data-hardware-cancel-order', $html);
    }

    public function test_hardware_dashboard_hides_cancel_action_for_unauthorized_user(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);
        foreach (['dashboard.hardware.view', 'incidents.view', 'orders.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $viewer->givePermissionTo($permission);
        }

        $html = $this->actingAs($viewer)
            ->get(route('dashboard', $this->dashboardQuery(['queue' => 'hardware'])))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-hardware-preship-cancel-enabled', $html);
        $this->assertStringNotContainsString('data-hardware-cancel-order', $html);
    }

    public function test_hardware_dashboard_shows_cancel_action_when_authorized_and_flag_on(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [$actor] = array_slice($this->paidHardwareFixtures(), 0, 1);

        $html = $this->actingAs($actor)
            ->get(route('dashboard', $this->dashboardQuery(['queue' => 'hardware'])))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hardware-preship-cancel-enabled="1"', $html);
        $this->assertStringContainsString('data-hardware-cancel-order', $html);
    }

    public function test_preflight_and_json_store_for_eligible_order(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        Bus::fake([ClosePreShipmentCancellationIncidentJob::class]);
        [$actor, $order] = array_slice($this->paidHardwareFixtures(), 0, 2);

        $this->actingAs($actor)
            ->getJson(route('orders.pre-shipment-cancellations.preflight', $order))
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('can_submit', true);

        $this->actingAs($actor)
            ->postJson(route('orders.pre-shipment-cancellations.store', $order), [
                'cancellation_reason' => 'Customer requested cancel before ship.',
                'refund_preference' => 'wallet',
                'operator_notes' => 'Confirmed on call.',
                'idempotency_key' => 'psc-hw-modal-'.$order->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['reference_no', 'case_id']);

        $case = PreShipmentCancellationRequest::query()->firstOrFail();
        $meta = PreShipmentCancellationOperatorMetadata::decode($case->blocker_reason);
        $this->assertSame('wallet', $meta['refund_preference']);
        $this->assertSame('Confirmed on call.', $meta['operator_notes']);
        $this->assertNotNull($case->committed_at);
        $this->assertNotNull(
            CommerceOrder::query()->where('support_order_id', $order->id)->value('hardware_fulfilment_blocked_at'),
        );
    }

    public function test_preflight_rejects_serial_allocated_order(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [$actor, $order] = $this->paidHardwareFixtures(hfState: HardwareFulfilmentState::SerialsAllocated);

        $this->actingAs($actor)
            ->getJson(route('orders.pre-shipment-cancellations.preflight', $order))
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('eligibility_code', PreShipmentEligibilityFailureCode::FulfilmentBeyondScope->value);
    }

    public function test_preflight_rejects_awb_assigned_order(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [$actor, $order, , $fulfilment] = $this->paidHardwareFixtures(hfState: HardwareFulfilmentState::Ingested);
        $fulfilment->forceFill(['awb' => 'AWB-HW-TEST'])->save();

        $this->actingAs($actor)
            ->getJson(route('orders.pre-shipment-cancellations.preflight', $order))
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('eligibility_code', PreShipmentEligibilityFailureCode::ShipmentOrAwb->value);
    }

    public function test_store_rechecks_eligibility_at_submission(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [$actor, $order, , $fulfilment] = $this->paidHardwareFixtures(hfState: HardwareFulfilmentState::ReadyForFulfilment);

        $this->actingAs($actor)
            ->getJson(route('orders.pre-shipment-cancellations.preflight', $order))
            ->assertJsonPath('eligible', true);

        $fulfilment->forceFill(['state' => HardwareFulfilmentState::SerialsAllocated])->save();

        try {
            app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
                'cancellation_reason' => 'Should fail after serial allocation.',
                'idempotency_key' => 'psc-hw-elig-'.$order->id,
            ]);
            $this->fail('Expected eligibility ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, PreShipmentCancellationRequest::query()->count());
    }

    public function test_split_tender_breakdown_and_case_flags_preserved_on_json_submit(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [$actor, $order] = $this->paidHardwareFixtures(orderId: 'RDE9102002', walletTender: 400);

        $preflight = $this->actingAs($actor)
            ->getJson(route('orders.pre-shipment-cancellations.preflight', $order))
            ->assertOk()
            ->json();

        $this->assertTrue($preflight['payment_breakdown']['split_tender']);

        $this->actingAs($actor)
            ->postJson(route('orders.pre-shipment-cancellations.store', $order), [
                'cancellation_reason' => 'Split tender cancel from hardware dashboard.',
                'refund_preference' => 'opm',
                'idempotency_key' => 'psc-hw-split-'.$order->id,
            ])
            ->assertOk();

        $case = PreShipmentCancellationRequest::query()->firstOrFail();
        $this->assertTrue($case->split_tender);
        $this->assertTrue($case->execution_hold);
        $this->assertSame(PreShipmentCancellationStatus::FinanceReview, $case->status);
    }

    public function test_opm_refund_preference_persists_in_blocker_reason(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [$actor, $order] = array_slice($this->paidHardwareFixtures(orderId: 'RBP9103003'), 0, 2);

        $this->actingAs($actor)
            ->postJson(route('orders.pre-shipment-cancellations.store', $order), [
                'cancellation_reason' => 'Refund to original payment method.',
                'refund_preference' => 'opm',
                'idempotency_key' => 'psc-hw-opm-'.$order->id,
            ])
            ->assertOk();

        $meta = PreShipmentCancellationOperatorMetadata::decode(
            PreShipmentCancellationRequest::query()->firstOrFail()->blocker_reason,
        );
        $this->assertSame('opm', $meta['refund_preference']);
    }

    public function test_duplicate_json_submissions_are_idempotent(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [$actor, $order] = array_slice($this->paidHardwareFixtures(orderId: 'RDE9104004'), 0, 2);
        $key = 'psc-hw-dup-'.$order->id;
        $payload = [
            'cancellation_reason' => 'Duplicate submission guard from modal.',
            'refund_preference' => 'wallet',
            'idempotency_key' => $key,
        ];

        $this->actingAs($actor)->postJson(route('orders.pre-shipment-cancellations.store', $order), $payload)->assertOk();
        $this->actingAs($actor)->postJson(route('orders.pre-shipment-cancellations.store', $order), $payload)->assertOk();

        $this->assertSame(1, PreShipmentCancellationRequest::query()->count());
    }

    public function test_failed_validation_does_not_create_cancellation_row(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [, $order] = array_slice($this->paidHardwareFixtures(), 0, 2);

        $validator = Validator::make([
            'cancellation_reason' => 'short',
        ], (new \App\Http\Requests\StorePreShipmentCancellationRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertSame(0, PreShipmentCancellationRequest::query()->where('order_id', $order->id)->count());
    }
}
