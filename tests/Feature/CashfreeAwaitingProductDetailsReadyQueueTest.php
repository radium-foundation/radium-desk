<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CashfreeAwaitingProductDetailsReadyQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
        ]);
    }

    public function test_cashfree_awaiting_product_details_becomes_open_and_assigns_on_validation_success(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('ready-admin@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: '7881953',
            product: 'MFS110',
        );

        $this->assertSame(IncidentStatus::AwaitingProductDetails, $incident->status);
        $this->assertNull($incident->assigned_to_user_id);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id, [
            'lookup_result' => 'data_received',
        ]);

        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);
        $this->assertTrue($eligibility->passesValidationForOrder($incident->order->fresh()));

        $eligibility->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($fresh),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.status_changed',
            'auditable_id' => $incident->id,
            'auditable_type' => $incident->getMorphClass(),
        ]);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.status_changed')
            ->where('new_values->status', IncidentStatus::Open->value)
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_status_promotion_is_idempotent_across_repeated_eligibility_evaluations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('idempotent-admin@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: '7881953',
            product: 'MFS110',
        );

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id);

        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);
        $eligibility->evaluateAssignmentEligibility($incident->order->fresh(), $actor);
        $eligibility->evaluateAssignmentEligibility($incident->order->fresh(), $actor);
        $eligibility->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $incident->fresh();

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.status_changed')
            ->where('new_values->status', IncidentStatus::Open->value)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count());

        Carbon::setTestNow();
    }

    public function test_open_non_cashfree_workflow_remains_unchanged_on_validation_success(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('open-admin@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-OPEN-READY-'.uniqid(),
            'serial_number' => '7881953',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Manual open case',
            'description' => 'Already open non-Cashfree case.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'created_by' => $actor->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($order->fresh(), $actor);

        $fresh = $incident->fresh();

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.status_changed')
            ->count());

        Carbon::setTestNow();
    }

    public function test_validation_failure_does_not_promote_awaiting_product_details(): void
    {
        $admin = $this->createAdmin('fail-admin@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: null,
            product: null,
        );

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $incident->fresh();

        $this->assertSame(IncidentStatus::AwaitingProductDetails, $fresh->status);
        $this->assertNull($fresh->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.status_changed')
            ->count());
    }

    private function createCashfreeAwaitingIncident(
        User $actor,
        ?string $serial,
        ?string $product,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => 'RD-CF-READY-'.uniqid(),
            'serial_number' => $serial,
            'product_name' => $product,
            'device_model' => $product,
            'cashfree_payment_id' => 'cf_'.uniqid(),
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — '.$order->order_id,
            'description' => 'Automatically created from Cashfree payment webhook. Awaiting product details.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'assigned_to_user_id' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function createAdmin(string $email): User
    {
        $admin = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function configureShiftAdmin(int $adminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $adminId,
            'assignment.night_shift_admin_user_id' => (string) $adminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }
}
