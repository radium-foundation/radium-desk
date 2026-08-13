<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\AssignmentOrigin;
use App\Enums\CommercialState;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RefundStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Commercial\CommercialServiceRestorationService;
use App\Services\Commercial\CommercialStateResolver;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseStatusService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadyQueueCommercialRefundedExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'commercial_state.enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_refund_completed_with_reference_missing_is_not_ready(): void
    {
        [$admin, $incident, $order] = $this->createReadyWalletRefundFixture();

        $this->assertRefundCompletedNotReady($incident, $order);
    }

    public function test_refund_completed_after_customer_reopen_is_not_ready(): void
    {
        [$admin, $incident, $order, $refund] = $this->createReadyWalletRefundFixture();

        app(ServiceCaseStatusService::class)->updateStatus(
            $incident,
            IncidentStatus::Closed,
            $admin,
        );

        app(ServiceCaseStatusService::class)->reopen($incident->fresh(), $admin);

        $fresh = $this->freshIncident($incident->fresh());
        $fresh->update([
            'assignment_origin' => AssignmentOrigin::Refund,
            'assigned_to_user_id' => $admin->id,
        ]);

        $this->assertSame(RefundStatus::Closed, $refund->fresh()->status);
        $this->assertRefundCompletedNotReady($fresh, $order);
    }

    public function test_refund_initiated_preserves_existing_ready_eligibility(): void
    {
        [$admin, $incident, $order] = $this->createReadyValidatedFixture();

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-PENDING-'.uniqid(),
            'amount' => 617,
            'refund_amount' => 617,
            'reason' => 'Pending refund should not remove Ready membership.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $fresh = $this->freshIncident($incident);

        $this->assertSame(
            CommercialState::RefundInitiated,
            app(CommercialStateResolver::class)->forIncident($fresh)->state,
        );
        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $fresh),
        );
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($fresh));
    }

    public function test_service_restored_with_reference_missing_is_ready_when_gates_pass(): void
    {
        [$admin, $incident, $order, $refund] = $this->createReadyWalletRefundFixture();

        app(CommercialServiceRestorationService::class)->restore($order, $refund, $admin, [
            'finance_verified' => true,
            'wallet_reversed_externally' => true,
            'wallet_reversal_reference' => 'RD-RESTORE-READY',
        ]);

        app(DashboardSnapshotStore::class)->forget();
        $fresh = $this->freshIncident($incident);

        $this->assertSame(CommercialState::ServiceRestored, app(CommercialStateResolver::class)->forIncident($fresh)->state);
        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $fresh),
        );
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($fresh));
        $this->assertTrue(
            DashboardSnapshot::load()
                ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id),
        );
    }

    public function test_revoking_service_restoration_returns_refund_completed_and_excludes_ready(): void
    {
        [$admin, $incident, $order, $refund] = $this->createReadyWalletRefundFixture();

        $restoration = app(CommercialServiceRestorationService::class)->restore($order, $refund, $admin, [
            'finance_verified' => true,
            'wallet_reversed_externally' => true,
            'wallet_reversal_reference' => 'RD-REVOKE-READY',
        ]);

        app(CommercialServiceRestorationService::class)->revoke($restoration, $admin);

        app(DashboardSnapshotStore::class)->forget();
        $fresh = $this->freshIncident($incident);

        $this->assertSame(CommercialState::RefundCompleted, app(CommercialStateResolver::class)->forIncident($fresh)->state);
        $this->assertRefundCompletedNotReady($fresh, $order);
    }

    public function test_open_commercial_state_preserves_existing_ready_eligibility(): void
    {
        [$admin, $incident, $order] = $this->createReadyValidatedFixture();
        $fresh = $this->freshIncident($incident);

        $this->assertSame(CommercialState::Open, app(CommercialStateResolver::class)->forIncident($fresh)->state);
        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order, $fresh),
        );
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($fresh));
    }

    public function test_commercial_state_disabled_preserves_legacy_ready_eligibility_for_refund_completed(): void
    {
        config(['commercial_state.enabled' => false]);

        [$admin, $incident, $order] = $this->createReadyWalletRefundFixture();
        $fresh = $this->freshIncident($incident);

        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $fresh),
        );
        unset($admin);
    }

    public function test_restoration_forgets_snapshot_and_broadcasts_queue_membership(): void
    {
        $snapshotSpy = $this->spy(DashboardSnapshotStore::class);
        $this->app->instance(DashboardSnapshotStore::class, $snapshotSpy);

        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        [$admin, $incident, $order, $refund] = $this->createReadyWalletRefundFixture();

        app(CommercialServiceRestorationService::class)->restore($order, $refund, $admin, [
            'finance_verified' => true,
            'wallet_reversed_externally' => true,
            'wallet_reversal_reference' => 'RD-BROADCAST-RESTORE',
        ]);

        $snapshotSpy->shouldHaveReceived('forget')->once();
        $broadcastSpy->shouldHaveReceived('serviceCaseQueueMembershipChanged')
            ->once()
            ->withArgs(function (Incident $broadcastIncident, ?User $actor) use ($incident, $admin): bool {
                return $broadcastIncident->id === $incident->id
                    && $actor?->id === $admin->id;
            });
    }

    public function test_revoke_forgets_snapshot_and_broadcasts_queue_membership(): void
    {
        $snapshotSpy = $this->spy(DashboardSnapshotStore::class);
        $this->app->instance(DashboardSnapshotStore::class, $snapshotSpy);

        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        [$admin, $incident, $order, $refund] = $this->createReadyWalletRefundFixture();

        $restoration = app(CommercialServiceRestorationService::class)->restore($order, $refund, $admin, [
            'finance_verified' => true,
            'wallet_reversed_externally' => true,
            'wallet_reversal_reference' => 'RD-BROADCAST-REVOKE',
        ]);

        app(CommercialServiceRestorationService::class)->revoke($restoration, $admin);

        $snapshotSpy->shouldHaveReceived('forget')->twice();
        $broadcastSpy->shouldHaveReceived('serviceCaseQueueMembershipChanged')
            ->twice()
            ->withArgs(function (Incident $broadcastIncident, ?User $actor) use ($incident, $admin): bool {
                return $broadcastIncident->id === $incident->id
                    && $actor?->id === $admin->id;
            });
    }

    private function assertRefundCompletedNotReady(Incident $incident, Order $order): void
    {
        $fresh = $this->freshIncident($incident);
        $order = $order->fresh();

        $this->assertSame(CommercialState::RefundCompleted, app(CommercialStateResolver::class)->forIncident($fresh)->state);
        $this->assertFalse(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order, $fresh),
        );
        $this->assertNotSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($fresh));
        $this->assertFalse(
            DashboardSnapshot::load()
                ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id),
        );
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createReadyValidatedFixture(string $orderId = 'RD-RQ-OPEN'): array
    {
        $admin = $this->adminUser();
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

        return [$admin, $incident, $order];
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order, 3: RefundRequest}
     */
    private function createReadyWalletRefundFixture(string $orderId = 'RD-RQ-REFUNDED'): array
    {
        [$admin, $incident, $order] = $this->createReadyValidatedFixture($orderId);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-RQ-'.uniqid(),
            'amount' => 617,
            'refund_amount' => 617,
            'reason' => 'Wallet refund completed for Ready exclusion test.',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDay(),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
            'execution_reference_no' => 'REF-RQ-EXEC',
        ]);

        return [$admin, $incident, $order, $refund];
    }

    private function createIncident(Order $order, User $creator, User $assignee): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$order->order_id}",
            'description' => "Case {$order->order_id}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $creator->id,
        ]);
    }

    private function freshIncident(Incident $incident): Incident
    {
        return $incident->fresh([
            'order',
            'assignee.roles',
            'activeWaitingState',
            'supportAppointments',
            'activeBusinessHold',
            'refundRequests',
        ]);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }
}
