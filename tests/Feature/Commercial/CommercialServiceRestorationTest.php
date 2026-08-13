<?php

namespace Tests\Feature\Commercial;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CommercialAction;
use App\Enums\CommercialState;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RefundStatus;
use App\Models\CommercialServiceRestoration;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Commercial\CommercialServiceRestorationService;
use App\Services\Commercial\CommercialStateResolver;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderTransactionService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialServiceRestorationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
        config(['commercial_state.enabled' => true]);
    }

    public function test_refund_completed_wallet_blocks_assign_reference(): void
    {
        [$admin, $incident, $order] = $this->createWalletRefundFixture();

        $this->actingAs($admin);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());
        $this->assertSame(CommercialState::RefundCompleted, $snapshot->state);
        $this->assertTrue($snapshot->blocks(CommercialAction::AssignServiceReference));

        try {
            app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-BLOCKED', $admin, broadcast: false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_id', $exception->errors());
        }
    }

    public function test_restoration_allows_assign_reference_without_mutating_refund(): void
    {
        [$admin, $incident, $order, $refund] = $this->createWalletRefundFixture();
        $refundSnapshotBefore = [
            'id' => $refund->id,
            'status' => $refund->status?->value,
            'approved_refund_method' => $refund->approved_refund_method?->value,
            'refund_amount' => (string) $refund->refund_amount,
            'executed_at' => $refund->executed_at?->toIso8601String(),
            'closed_at' => $refund->closed_at?->toIso8601String(),
            'execution_reference_no' => $refund->execution_reference_no,
            'updated_at' => $refund->updated_at?->toIso8601String(),
        ];

        $this->actingAs($admin);

        $freshBefore = $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'supportAppointments', 'activeBusinessHold', 'refundRequests']);
        $this->assertFalse(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $freshBefore),
        );
        $this->assertNotSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($freshBefore),
        );

        $response = $this->postJson(route('dashboard.service-cases.customer-360.commercial-service-restore', [
            'incident' => $incident,
            'refund' => $refund,
        ]), [
            'finance_verified' => '1',
            'wallet_reversed_externally' => '1',
            'wallet_reversal_reference' => 'RD273105-REV',
            'finance_note' => 'Finance confirmed wallet debit.',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());
        $this->assertSame(CommercialState::ServiceRestored, $snapshot->state);
        $this->assertFalse($snapshot->blocks(CommercialAction::AssignServiceReference));
        $this->assertTrue($snapshot->allowsCommercialWork());

        $fresh = $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'supportAppointments', 'activeBusinessHold', 'refundRequests']);
        app(DashboardSnapshotStore::class)->forget();

        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $fresh),
        );
        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($fresh),
        );
        $this->assertTrue(
            DashboardSnapshot::load()
                ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id),
        );

        $assigned = app(OrderTransactionService::class)->assignTransactionId(
            $order->fresh(),
            'TXN-RESTORED-001',
            $admin,
            broadcast: false,
        );
        $this->assertSame('TXN-RESTORED-001', $assigned->transaction_id);

        $freshAfterAssign = $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'supportAppointments', 'activeBusinessHold', 'refundRequests']);
        app(DashboardSnapshotStore::class)->forget();
        $this->assertFalse(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $freshAfterAssign),
        );

        $refund->refresh();
        $this->assertSame($refundSnapshotBefore, [
            'id' => $refund->id,
            'status' => $refund->status?->value,
            'approved_refund_method' => $refund->approved_refund_method?->value,
            'refund_amount' => (string) $refund->refund_amount,
            'executed_at' => $refund->executed_at?->toIso8601String(),
            'closed_at' => $refund->closed_at?->toIso8601String(),
            'execution_reference_no' => $refund->execution_reference_no,
            'updated_at' => $refund->updated_at?->toIso8601String(),
        ]);
        $this->assertSame(RefundStatus::Closed, $refund->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'commercial.service_restored',
            'user_id' => $admin->id,
        ]);

        $html = $this->get(route('dashboard.service-cases.customer-360', $incident))->assertOk()->getContent();
        $this->assertStringContainsString('data-commercial-state="service_restored"', $html);
        $this->assertStringContainsString('Service Restored', $html);
        $this->assertStringContainsString('Revoke Restoration', $html);
    }

    public function test_revoking_restoration_restores_commercial_block(): void
    {
        [$admin, $incident, $order, $refund] = $this->createWalletRefundFixture();
        $this->actingAs($admin);

        $restoration = app(CommercialServiceRestorationService::class)->restore($order, $refund, $admin, [
            'finance_verified' => true,
            'wallet_reversed_externally' => true,
            'wallet_reversal_reference' => 'RD273105-REV',
        ]);

        $this->assertSame(
            CommercialState::ServiceRestored,
            app(CommercialStateResolver::class)->forIncident($incident->fresh())->state,
        );

        $response = $this->postJson(route('dashboard.service-cases.customer-360.commercial-service-restore.revoke', [
            'incident' => $incident,
            'restoration' => $restoration,
        ]));

        $response->assertOk()->assertJson(['success' => true]);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());
        $this->assertSame(CommercialState::RefundCompleted, $snapshot->state);
        $this->assertTrue($snapshot->blocks(CommercialAction::AssignServiceReference));

        $freshAfterRevoke = $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'supportAppointments', 'activeBusinessHold', 'refundRequests']);
        app(DashboardSnapshotStore::class)->forget();
        $this->assertFalse(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $freshAfterRevoke),
        );
        $this->assertNotSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($freshAfterRevoke),
        );

        $this->assertNotNull($restoration->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'commercial.service_restoration_revoked',
            'user_id' => $admin->id,
        ]);
    }

    public function test_agent_cannot_restore_commercial_service(): void
    {
        [$admin, $incident, , $refund] = $this->createWalletRefundFixture();
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent);

        $this->postJson(route('dashboard.service-cases.customer-360.commercial-service-restore', [
            'incident' => $incident,
            'refund' => $refund,
        ]), [
            'finance_verified' => '1',
            'wallet_reversed_externally' => '1',
            'wallet_reversal_reference' => 'RD273105-REV',
        ])->assertForbidden();

        $this->assertSame(0, CommercialServiceRestoration::query()->count());
        unset($admin);
    }

    public function test_non_wallet_refund_cannot_be_restored(): void
    {
        [$admin, $incident, $order, $refund] = $this->createWalletRefundFixture(
            method: ApprovedRefundMethod::BankTransfer,
        );

        $this->actingAs($admin);

        $this->postJson(route('dashboard.service-cases.customer-360.commercial-service-restore', [
            'incident' => $incident,
            'refund' => $refund,
        ]), [
            'finance_verified' => '1',
            'wallet_reversed_externally' => '1',
            'wallet_reversal_reference' => 'BANK-REV',
        ])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(
            CommercialState::RefundCompleted,
            app(CommercialStateResolver::class)->forIncident($incident->fresh())->state,
        );
        unset($order);
    }

    public function test_customer360_shows_restore_action_for_wallet_refund_completed(): void
    {
        [$admin, $incident] = $this->createWalletRefundFixture();
        $this->actingAs($admin);

        $html = $this->get(route('dashboard.service-cases.customer-360', $incident))->assertOk()->getContent();
        $this->assertStringContainsString('Restore Commercial Service', $html);
        $this->assertStringContainsString('data-commercial-state="refund_completed"', $html);
    }

    public function test_duplicate_active_restoration_is_rejected(): void
    {
        [$admin, $incident, $order, $refund] = $this->createWalletRefundFixture();
        $this->actingAs($admin);

        app(CommercialServiceRestorationService::class)->restore($order, $refund, $admin, [
            'finance_verified' => true,
            'wallet_reversed_externally' => true,
            'wallet_reversal_reference' => 'RD273105-REV',
        ]);

        $this->postJson(route('dashboard.service-cases.customer-360.commercial-service-restore', [
            'incident' => $incident,
            'refund' => $refund,
        ]), [
            'finance_verified' => '1',
            'wallet_reversed_externally' => '1',
            'wallet_reversal_reference' => 'RD273105-REV-2',
        ])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(1, CommercialServiceRestoration::query()->active()->count());
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order, 3: RefundRequest}
     */
    private function createWalletRefundFixture(
        ApprovedRefundMethod $method = ApprovedRefundMethod::Wallet,
    ): array {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-CSR-'.uniqid(),
            'serial_number' => '7881953',
            'product_name' => $deviceModel->name,
            'device_model' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'customer_name' => 'Restore Customer',
            'customer_email' => 'restore@example.com',
            'customer_phone' => '9000002843',
            'cashfree_payment_id' => 'CF-CSR-'.uniqid(),
            'payment_amount' => 617,
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'created_by' => $admin->id,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal->value,
            'title' => 'Commercial restoration fixture',
            'description' => 'Commercial restoration fixture description.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-CSR-'.uniqid(),
            'amount' => 617,
            'refund_amount' => 617,
            'reason' => 'Wallet refund for commercial restoration test.',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => $method,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDay(),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
            'execution_reference_no' => 'REF-CSR-EXEC',
        ]);

        return [$admin, $incident, $order, $refund];
    }
}
