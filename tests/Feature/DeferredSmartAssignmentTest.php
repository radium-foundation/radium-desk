<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityChangeSource;
use App\Enums\TeamAvailabilityStatus;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\DeferredSmartAssignmentService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamAvailabilityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SupportAppointmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeferredSmartAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $shiftAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'smart_assignment.enabled' => true,
            'smart_assignment.deferred.enabled' => true,
            'smart_assignment.deferred.batch_size' => 5,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'name' => 'Ira',
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->shiftAdmin = User::factory()->create(['name' => 'Shift Admin']);
        $this->shiftAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_eligible_engineer_at_booking_does_not_mark_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Ready Agent', TeamAvailabilityStatus::Available);
        $incident = $this->createShiftAdminOwnedIncident();

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertFalse($incident->pending_smart_assignment);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->whereIn('event', [
                'service_case.pending_smart_assignment',
                'service_case.deferred_smart_assignment',
            ])
            ->count());
    }

    public function test_no_eligible_engineer_keeps_shift_admin_and_marks_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        Notification::fake();

        $notifyAdmin = User::factory()->create();
        $notifyAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($this->shiftAdmin->id, $incident->assigned_to_user_id);
        $this->assertTrue($incident->pending_smart_assignment);
        $this->assertTrue($incident->isPendingSmartAssignment());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.smart_assignment_unassigned',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.pending_smart_assignment',
            'auditable_id' => $incident->id,
        ]);

        Notification::assertSentTo($notifyAdmin, \App\Notifications\SmartAssignmentUnassignedNotification::class);
    }

    public function test_login_session_start_assigns_pending_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        Event::fake([SupportAppointmentSmartAssigned::class]);

        $agent = $this->createSupportAgent('Deferred Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($this->shiftAdmin->id, $incident->assigned_to_user_id);
        $this->assertTrue($incident->pending_smart_assignment);

        app(PresenceEngineService::class)->startSession($agent);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertFalse($incident->pending_smart_assignment);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.deferred_smart_assignment',
            'auditable_id' => $incident->id,
        ]);

        Event::assertDispatched(SupportAppointmentSmartAssigned::class);
    }

    public function test_availability_change_to_available_assigns_pending_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();
        $this->bookAppointment($incident);
        $this->assertTrue($incident->fresh()->pending_smart_assignment);
        $this->assertSame($this->shiftAdmin->id, $incident->fresh()->assigned_to_user_id);

        config(['smart_assignment.deferred.enabled' => false]);
        app(PresenceEngineService::class)->startSession($agent);
        app(TeamAvailabilityService::class)->updateStatus(
            user: $agent->fresh(),
            status: TeamAvailabilityStatus::Busy,
            actor: $agent,
            source: TeamAvailabilityChangeSource::Manual,
        );
        config(['smart_assignment.deferred.enabled' => true]);

        app(TeamAvailabilityService::class)->updateStatus(
            user: $agent->fresh(),
            status: TeamAvailabilityStatus::Available,
            actor: $agent,
            source: TeamAvailabilityChangeSource::Manual,
        );

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertFalse($incident->pending_smart_assignment);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.deferred_smart_assignment',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_batch_size_limits_deferred_assignments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
        config(['smart_assignment.deferred.batch_size' => 5]);

        $this->createSupportAgent('Offline Only', TeamAvailabilityStatus::Offline);

        $incidents = collect(range(1, 6))->map(
            fn (int $i): Incident => $this->createShiftAdminOwnedIncident('RD-BATCH-'.$i),
        );

        foreach ($incidents as $incident) {
            $this->bookAppointment($incident);
            $fresh = $incident->fresh();
            $this->assertTrue($fresh->pending_smart_assignment);
            $this->assertSame($this->shiftAdmin->id, $fresh->assigned_to_user_id);
        }

        config(['smart_assignment.deferred.enabled' => false]);
        $agent = $this->createSupportAgent('Batch Agent', TeamAvailabilityStatus::Available);
        config(['smart_assignment.deferred.enabled' => true]);

        $assignedCount = app(DeferredSmartAssignmentService::class)->processPendingBatch();

        $this->assertSame(5, $assignedCount);
        $this->assertSame(5, Incident::query()
            ->whereIn('id', $incidents->pluck('id'))
            ->where('assigned_to_user_id', $agent->id)
            ->where('pending_smart_assignment', false)
            ->count());
        $this->assertSame(1, Incident::query()
            ->whereIn('id', $incidents->pluck('id'))
            ->pendingSmartAssignment()
            ->where('assigned_to_user_id', $this->shiftAdmin->id)
            ->count());
    }

    public function test_deferred_recalculates_best_assignee_per_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('Offline Placeholder', TeamAvailabilityStatus::Offline);

        $pendingA = $this->createShiftAdminOwnedIncident('RD-BAL-A');
        $pendingB = $this->createShiftAdminOwnedIncident('RD-BAL-B');
        $this->bookAppointment($pendingA);
        $this->bookAppointment($pendingB);

        config(['smart_assignment.deferred.enabled' => false]);
        $heavy = $this->createSupportAgent('Heavy Agent', TeamAvailabilityStatus::Available);
        $light = $this->createSupportAgent('Light Agent', TeamAvailabilityStatus::Available);
        config(['smart_assignment.deferred.enabled' => true]);

        $this->createAssignedIncident($heavy, 'RD-HEAVY-1');
        $this->createAssignedIncident($heavy, 'RD-HEAVY-2');

        app(DeferredSmartAssignmentService::class)->processPendingBatch();

        $pendingA->refresh();
        $pendingB->refresh();

        $this->assertSame($light->id, $pendingA->assigned_to_user_id);
        $this->assertSame($light->id, $pendingB->assigned_to_user_id);
        $this->assertNotSame($heavy->id, $pendingA->assigned_to_user_id);
    }

    public function test_scheduler_command_processes_pending_cases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();
        $this->bookAppointment($incident);
        $this->assertTrue($incident->fresh()->pending_smart_assignment);
        $this->assertSame($this->shiftAdmin->id, $incident->fresh()->assigned_to_user_id);

        config(['smart_assignment.deferred.enabled' => false]);
        $agent = $this->createSupportAgent('Cron Agent', TeamAvailabilityStatus::Available);
        config(['smart_assignment.deferred.enabled' => true]);

        Artisan::call('service-cases:process-deferred-smart-assignment');

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertFalse($incident->pending_smart_assignment);
    }

    public function test_deferred_disabled_skips_processing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();
        $this->bookAppointment($incident);

        config(['smart_assignment.deferred.enabled' => false]);
        $this->createSupportAgent('Ignored Agent', TeamAvailabilityStatus::Available);

        $assigned = app(DeferredSmartAssignmentService::class)->processPendingBatch();

        $this->assertSame(0, $assigned);
        $incident->refresh();
        $this->assertSame($this->shiftAdmin->id, $incident->assigned_to_user_id);
        $this->assertTrue($incident->pending_smart_assignment);
    }

    public function test_pending_path_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        Notification::fake();

        $notifyAdmin = User::factory()->create();
        $notifyAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();
        $this->bookAppointment($incident);

        app(\App\Services\Operations\SupportAppointmentSmartAssignmentService::class)
            ->assignForActiveSupport($incident->fresh());

        $incident->refresh();
        $this->assertSame($this->shiftAdmin->id, $incident->assigned_to_user_id);

        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.pending_smart_assignment')
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.smart_assignment_unassigned')
            ->count());

        Notification::assertSentToTimes($notifyAdmin, \App\Notifications\SmartAssignmentUnassignedNotification::class, 1);
    }

    public function test_manual_assignment_clears_pending_and_blocks_deferred_reassignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertTrue($incident->pending_smart_assignment);
        $this->assertSame($this->shiftAdmin->id, $incident->assigned_to_user_id);

        config(['smart_assignment.deferred.enabled' => false]);
        $manualAgent = $this->createSupportAgent('Manual Agent', TeamAvailabilityStatus::Available);
        $otherEligible = $this->createSupportAgent('Other Eligible', TeamAvailabilityStatus::Available);
        config(['smart_assignment.deferred.enabled' => true]);

        app(ServiceCaseAssignmentService::class)->reassign(
            incident: $incident->fresh(),
            assignee: $manualAgent,
            actor: $this->shiftAdmin,
        );

        $incident->refresh();
        $this->assertSame($manualAgent->id, $incident->assigned_to_user_id);
        $this->assertFalse($incident->pending_smart_assignment);

        $assignedCount = app(DeferredSmartAssignmentService::class)->processPendingBatch();

        $incident->refresh();
        $this->assertSame(0, $assignedCount);
        $this->assertSame($manualAgent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($otherEligible->id, $incident->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.deferred_smart_assignment')
            ->count());
    }

    public function test_pending_without_manual_action_is_deferred_assigned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createShiftAdminOwnedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertTrue($incident->pending_smart_assignment);
        $this->assertSame($this->shiftAdmin->id, $incident->assigned_to_user_id);

        config(['smart_assignment.deferred.enabled' => false]);
        $agent = $this->createSupportAgent('Deferred Agent', TeamAvailabilityStatus::Available);
        config(['smart_assignment.deferred.enabled' => true]);

        $assignedCount = app(DeferredSmartAssignmentService::class)->processPendingBatch();

        $incident->refresh();
        $this->assertSame(1, $assignedCount);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertFalse($incident->pending_smart_assignment);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.deferred_smart_assignment',
            'auditable_id' => $incident->id,
        ]);
    }

    private function createSupportAgent(string $name, TeamAvailabilityStatus $status): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        if ($status !== TeamAvailabilityStatus::Offline) {
            app(PresenceEngineService::class)->startSession($user);
        }

        return $user->fresh();
    }

    private function createShiftAdminOwnedIncident(string $orderId = 'RD-DEFER-1'): Incident
    {
        $incident = $this->createUnassignedIncident($orderId);
        $incident->update(['assigned_to_user_id' => $this->shiftAdmin->id]);

        return $incident->fresh();
    }

    private function createUnassignedIncident(string $orderId = 'RD-DEFER-1'): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Deferred Assignment Customer',
            'customer_email' => 'defer@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Deferred smart assignment case',
            'description' => 'Deferred smart assignment case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => null,
        ]);
    }

    private function createAssignedIncident(User $assignee, string $orderId): Incident
    {
        $incident = $this->createUnassignedIncident($orderId);
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        return $incident->fresh();
    }

    private function bookAppointment(Incident $incident): SupportAppointment
    {
        return app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need remote support.',
        ]);
    }
}
