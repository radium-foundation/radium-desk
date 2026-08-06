<?php

namespace Tests\Feature;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReadyQueueOwnerPreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => true,
            'service_case_assignment.round_robin_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_support_origin_keeps_owner_when_ready_queue_validates(): void
    {
        [$admin, $agent, $actor, $incident] = $this->createOwnedReadyEligibleCase(
            origin: AssignmentOrigin::Support,
        );

        $this->evaluateReady($incident, $actor);

        $fresh = $incident->fresh();

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Support, $fresh->assignment_origin);
        $this->assertNotSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertOwnerPreservedAudit($incident, AssignmentOrigin::Support);
    }

    public function test_appointment_origin_keeps_owner_when_ready_queue_validates(): void
    {
        [$admin, $agent, $actor, $incident] = $this->createOwnedReadyEligibleCase(
            origin: AssignmentOrigin::AppointmentSmartAssignment,
        );

        $this->evaluateReady($incident, $actor);

        $fresh = $incident->fresh();

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::AppointmentSmartAssignment, $fresh->assignment_origin);
        $this->assertNotSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertOwnerPreservedAudit($incident, AssignmentOrigin::AppointmentSmartAssignment);
    }

    public function test_refund_origin_keeps_owner_when_ready_queue_validates(): void
    {
        [$admin, $agent, $actor, $incident] = $this->createOwnedReadyEligibleCase(
            origin: AssignmentOrigin::Refund,
        );

        $this->evaluateReady($incident, $actor);

        $fresh = $incident->fresh();

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Refund, $fresh->assignment_origin);
        $this->assertNotSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertOwnerPreservedAudit($incident, AssignmentOrigin::Refund);
    }

    public function test_manual_origin_keeps_owner_when_ready_queue_validates(): void
    {
        [$admin, $agent, $actor, $incident] = $this->createOwnedReadyEligibleCase(
            origin: AssignmentOrigin::Manual,
        );

        $this->evaluateReady($incident, $actor);

        $fresh = $incident->fresh();

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertNotSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertOwnerPreservedAudit($incident, AssignmentOrigin::Manual);
    }

    public function test_supervisor_manual_override_can_still_transfer_protected_owner(): void
    {
        [$admin, $agent, $actor, $incident] = $this->createOwnedReadyEligibleCase(
            origin: AssignmentOrigin::Support,
        );

        app(ServiceCaseAssignmentService::class)->reassign($incident->fresh(), $admin, $admin);

        $fresh = $incident->fresh();

        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);

        $audit = AuditLog::query()
            ->where('event', 'service_case.reassigned')
            ->where('auditable_id', $incident->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('manual_reassign', $audit->new_values['override_reason'] ?? null);
        $this->assertSame($agent->id, $audit->old_values['assigned_to_user_id'] ?? null);
        $this->assertSame($admin->id, $audit->new_values['assigned_to_user_id'] ?? null);
    }

    public function test_auto_origin_agent_still_reassigns_to_ready_admin(): void
    {
        [$admin, $agent, $actor, $incident] = $this->createOwnedReadyEligibleCase(
            origin: AssignmentOrigin::Auto,
        );

        $this->evaluateReady($incident, $actor);

        $fresh = $incident->fresh();

        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Auto, $fresh->assignment_origin);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => ServiceCaseAssignmentService::READY_QUEUE_OWNER_PRESERVED_EVENT,
            'auditable_id' => $incident->id,
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Incident}
     */
    private function createOwnedReadyEligibleCase(AssignmentOrigin $origin): array
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('ready-admin@example.com', 'Ready Admin');
        $agent = $this->createAgentUser('support-owner@example.com', 'Support Owner');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-OWN-PRESERVE-'.uniqid(),
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Ready ownership preserve',
            'description' => 'Ready ownership preserve.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'assignment_origin' => $origin,
            'created_by' => $actor->id,
        ]);

        return [$admin, $agent, $actor, $incident];
    }

    private function evaluateReady(Incident $incident, User $actor): void
    {
        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $incident->order()->firstOrFail(),
            $actor,
        );
    }

    private function assertOwnerPreservedAudit(Incident $incident, AssignmentOrigin $origin): void
    {
        $audit = AuditLog::query()
            ->where('event', ServiceCaseAssignmentService::READY_QUEUE_OWNER_PRESERVED_EVENT)
            ->where('auditable_id', $incident->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($origin->value, $audit->new_values['preserved_origin'] ?? null);
        $this->assertSame(
            'ready_queue_must_not_overwrite_human_ownership',
            $audit->new_values['reason'] ?? null,
        );
    }

    private function configureAssignmentSettings(int $dayAdminId, int $nightAdminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $nightAdminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
            'assignment.automation_grace_period_seconds' => '60',
        ]);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }
}
