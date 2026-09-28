<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Assignment\UniversalAssignmentEngine;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseAutomationGraceService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GraceExpiredValidationFailedAssignmentFallbackTest extends TestCase
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
            'universal_assignment.remove_shift_admin_fallback' => false,
        ]);
    }

    public function test_grace_expired_validation_passes_assigns_shift_admin_via_ready_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('day-admin@test.com');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-GRACE-VALID-'.uniqid(),
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Grace expired validation pass',
            'description' => 'Valid serial at expiry.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
            'automation_pending_until' => now()->subMinute(),
        ]);

        $this->assertSame(1, app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods());

        $incident->refresh();
        $this->assertSame($admin->id, $incident->assigned_to_user_id);
        $this->assertNull($incident->automation_pending_until);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'assignment.grace_expired_validation_failed_fallback',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_grace_expired_validation_fails_with_support_rr_available_assigns_agent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentUser('agent-a@test.com');
        $admin = $this->createAdminUser('day-admin@test.com');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createGracePendingIncident(
            actor: $actor,
            serialNumber: '94037066',
        );

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(1, app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods());

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.automation.validation_failed',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'assignment.grace_expired_validation_failed_fallback',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_grace_expired_validation_fails_with_empty_support_pool_assigns_shift_admin_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 11:25:03', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('day-admin@test.com');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createGracePendingIncident(
            actor: $actor,
            serialNumber: '94037066',
        );

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(1, app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods());

        $incident->refresh();
        $this->assertSame($admin->id, $incident->assigned_to_user_id);
        $this->assertNull($incident->automation_pending_until);
        $this->assertSame(IncidentStatus::AwaitingProductDetails, $incident->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.automation.validation_failed',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.automation.waiting_manual_correction',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'assignment.grace_expired_validation_failed_fallback',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'service_case.unassigned',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_grace_expired_validation_fails_with_empty_support_pool_and_disabled_fallback_stays_unassigned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 11:25:03', 'Asia/Kolkata'));

        config(['universal_assignment.remove_shift_admin_fallback' => true]);

        $admin = $this->createAdminUser('day-admin@test.com');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createGracePendingIncident(
            actor: $actor,
            serialNumber: '94037066',
        );

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(1, app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods());

        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.unassigned',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'assignment.grace_expired_validation_failed_fallback',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_grace_expired_fallback_assignment_is_idempotent_on_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 11:25:03', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('day-admin@test.com');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createGracePendingIncident(
            actor: $actor,
            serialNumber: '94037066',
        );

        Carbon::setTestNow(now()->addSeconds(61));

        $service = app(ServiceCaseAutomationGraceService::class);
        $this->assertSame(1, $service->processExpiredGracePeriods());
        $this->assertSame(0, $service->processExpiredGracePeriods());

        $incident->refresh();
        $this->assertSame($admin->id, $incident->assigned_to_user_id);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count());

        Carbon::setTestNow();
    }

    public function test_grace_expired_fallback_does_not_duplicate_assignment_when_already_assigned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 11:25:03', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('day-admin@test.com');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createGracePendingIncident(
            actor: $actor,
            serialNumber: '94037066',
        );

        Carbon::setTestNow(now()->addSeconds(61));

        app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods();
        $incident->refresh();

        $beforeCount = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count();

        app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods();

        $this->assertSame($beforeCount, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count());

        Carbon::setTestNow();
    }

    public function test_intake_shift_admin_fallback_remains_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com');
        $nightAdmin = $this->createAdminUser('night-admin@test.com');
        $this->configureAssignmentSettings($dayAdmin->id, $nightAdmin->id);

        $incident = $this->createOpenIncident();
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $result = app(UniversalAssignmentEngine::class)->assignForUnassignedIntake($incident, $systemUser);

        $this->assertSame($dayAdmin->id, $result->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.assignment_fallback',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'assignment.grace_expired_validation_failed_fallback',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_missing_serial_grace_expiry_still_skips_assignment_without_support_rr(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 11:25:03', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('day-admin@test.com');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-GRACE-MISSING-'.uniqid(),
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Missing serial grace test',
            'description' => 'Awaiting serial.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(1, app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods());

        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'assignment.grace_expired_validation_failed_fallback',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
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

    private function createAdminUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createGracePendingIncident(User $actor, string $serialNumber): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-GRACE-'.uniqid(),
            'serial_number' => $serialNumber,
            'product_name' => 'MFS110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Grace expired fallback test',
            'description' => 'Invalid serial for manual correction.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        return $incident->fresh(['order']);
    }

    private function createOpenIncident(): Incident
    {
        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-INTAKE-'.uniqid(),
            'serial_number' => 'SN-INTAKE-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Intake fallback test',
            'description' => '',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
