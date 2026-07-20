<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\BusinessHoldType;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RefundStatus;
use App\Enums\WaitingReason;
use App\Models\BusinessHold;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\BusinessHoldService;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderTransactionService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseStatusService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_refund_request_from_ready_queue_activates_hold_and_removes_from_ready_queue(): void
    {
        [$admin, $agent, $order, $incident] = $this->createReadyQueueCase();

        $classifier = app(OperationsQueueClassifier::class);
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($this->freshIncident($incident)));

        $refund = $this->submitRefund($agent, $order, $incident);

        app(DashboardSnapshotStore::class)->forget();
        $fresh = $this->freshIncident($incident);

        $this->assertDatabaseHas('business_holds', [
            'incident_id' => $incident->id,
            'hold_type' => BusinessHoldType::Refund->value,
            'source_type' => $refund->getMorphClass(),
            'source_id' => $refund->id,
            'cleared_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'business_hold.activated',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        $this->assertFalse(
            app(ServiceCaseAssignmentEligibilityService::class)
                ->isReadyForReferenceEntry($order->fresh(), $fresh),
        );
        $this->assertSame(OperationQueue::BusinessHold, $classifier->classify($fresh));
        $this->assertFalse(
            DashboardSnapshot::load()
                ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id),
        );
        $this->assertTrue(
            DashboardSnapshot::load()
                ->incidentsForQueue(OperationQueue::BusinessHold->value)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id),
        );
    }

    public function test_refund_request_from_waiting_customer_queue_classifies_as_business_hold(): void
    {
        $admin = $this->adminUser();
        $agent = $this->agentUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-BH-WAITING',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_bh_waiting',
            'created_by' => $admin->id,
        ]);

        $incident = $this->createIncident($order, $admin, assignee: $agent);

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $admin,
        );

        $classifier = app(OperationsQueueClassifier::class);
        $this->assertSame(OperationQueue::WaitingCustomer, $classifier->classify($this->freshIncident($incident)));

        $this->submitRefund($agent, $order, $incident);

        app(DashboardSnapshotStore::class)->forget();
        $fresh = $this->freshIncident($incident);

        $this->assertSame(OperationQueue::BusinessHold, $classifier->classify($fresh));
        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($fresh, BusinessHoldType::Refund));
    }

    public function test_repair_command_skips_incident_with_refund_hold(): void
    {
        [$admin, $agent, $order, $incident] = $this->createReadyQueueCase(orderId: 'RD-BH-REPAIR-SKIP');

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $admin,
        );

        $this->submitRefund($agent, $order, $incident);

        $this->artisan('incidents:repair-serial-waiting')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('repaired: 0')
            ->expectsOutputToContain('skipped: 1')
            ->assertSuccessful();

        $fresh = $this->freshIncident($incident);
        $this->assertNotNull($fresh->activeWaitingState);
        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($fresh));
        $this->assertSame(OperationQueue::BusinessHold, app(OperationsQueueClassifier::class)->classify($fresh));
    }

    public function test_ref_number_entry_blocked_while_hold_active(): void
    {
        [$admin, $agent, $order, $incident] = $this->createReadyQueueCase(orderId: 'RD-BH-REFNO');

        $this->submitRefund($agent, $order, $incident);

        $this->expectException(ValidationException::class);

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order->fresh(),
            transactionId: 'TXN-BLOCKED-001',
            actor: $admin,
        );
    }

    public function test_case_closure_blocked_while_hold_active(): void
    {
        [$admin, $agent, $order, $incident] = $this->createReadyQueueCase(orderId: 'RD-BH-CLOSE');

        $this->submitRefund($agent, $order, $incident);

        $this->expectException(ValidationException::class);

        app(ServiceCaseStatusService::class)->updateStatus(
            $incident->fresh(),
            IncidentStatus::Closed,
            $admin,
        );
    }

    public function test_refund_approved_keeps_hold_active_until_completion(): void
    {
        [$admin, $agent, $order, $incident] = $this->createReadyQueueCase(orderId: 'RD-BH-APPROVE');

        $refund = $this->submitRefund($agent, $order, $incident);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::BankTransfer->value,
                'deduction_profile_key' => 'full_refund',
                'refund_amount' => 1000,
                'partial_difference_reason' => 'partial_refund',
                'review_notes' => 'Approved for bank transfer.',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));
        $this->assertSame(
            OperationQueue::BusinessHold,
            app(OperationsQueueClassifier::class)->classify($this->freshIncident($incident)),
        );
    }

    public function test_refund_completed_clears_hold_and_closes_incident(): void
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        [$admin, $agent, $order, $incident] = $this->createReadyQueueCase(orderId: 'RD-BH-COMPLETE');

        $refund = $this->submitRefund($agent, $order, $incident);

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => ApprovedRefundMethod::BankTransfer->value,
                'deduction_profile_key' => 'full_refund',
                'refund_amount' => 1000,
                'partial_difference_reason' => 'partial_refund',
                'review_notes' => 'Approved.',
            ]);

        $refund->refresh();

        $refund->update(['communication_channels' => []]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-BH-001',
                'execution_transaction_id' => 'TXN-BH-001',
                'execution_remarks' => 'Refund executed.',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $incident->refresh();
        $refund->refresh();

        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));
        $this->assertDatabaseHas('business_holds', [
            'incident_id' => $incident->id,
            'hold_type' => BusinessHoldType::Refund->value,
        ]);
        $this->assertNotNull(BusinessHold::query()->where('incident_id', $incident->id)->value('cleared_at'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'business_hold.cleared',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_refund_rejected_clears_hold_and_reassigns_to_requesting_agent(): void
    {
        $admin = $this->adminUser();
        $agent = $this->agentUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-BH-REJECT',
            'serial_number' => '7881953',
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_bh_reject',
            'created_by' => $admin->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $refund = $this->submitRefund($agent, $order, $incident);
        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));

        $this->actingAs($admin)
            ->post(route('refunds.reject', $refund), [
                'review_notes' => 'Refund not eligible under current policy.',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $incident->refresh();
        $refund->refresh();

        $this->assertSame(RefundStatus::Rejected, $refund->status);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_refund_rejected_does_not_auto_enter_ready_queue(): void
    {
        $admin = $this->adminUser();
        $agent = $this->agentUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-BH-NO-READY',
            'serial_number' => '7881954',
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_bh_no_ready',
            'created_by' => $admin->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $classifier = app(OperationsQueueClassifier::class);
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($this->freshIncident($incident)));

        $refund = $this->submitRefund($agent, $order, $incident);

        $this->actingAs($admin)
            ->post(route('refunds.reject', $refund), [
                'review_notes' => 'Refund denied after review.',
            ]);

        app(DashboardSnapshotStore::class)->forget();
        $fresh = $this->freshIncident($incident);

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertNotSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertFalse(
            app(\App\Services\ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh),
        );
    }

    /**
     * @return array{0: User, 1: User, 2: Order, 3: Incident}
     */
    private function createReadyQueueCase(string $orderId = 'RD-BH-READY'): array
    {
        $admin = $this->adminUser();
        $agent = $this->agentUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'created_by' => $admin->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        return [$admin, $agent, $order, $incident];
    }

    private function submitRefund(User $agent, Order $order, Incident $incident): RefundRequest
    {
        $this->actingAs($agent)->post(route('refunds.store'), [
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'amount' => 1000,
            'reason' => 'Customer requested cancellation and full refund.',
            'remarks' => 'Customer confirmed refund request through support channel.',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
        ])->assertRedirect();

        return RefundRequest::query()
            ->where('incident_id', $incident->id)
            ->firstOrFail();
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function agentUser(): User
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function createIncident(
        Order $order,
        User $creator,
        ?User $assignee = null,
    ): Incident {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$order->order_id}",
            'description' => "Case {$order->order_id}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function freshIncident(Incident $incident): Incident
    {
        return $incident->fresh([
            'order',
            'assignee.roles',
            'activeWaitingState',
            'activeBusinessHold',
            'supportAppointments',
        ]);
    }
}
