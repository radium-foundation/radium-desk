<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderSerialService;
use App\Services\OrderTransactionService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppointmentDoesNotBlockReadyPromotionTest extends TestCase
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
            'smart_assignment.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(DashboardSnapshotStore::class)->forget();

        parent::tearDown();
    }

    public function test_validation_pass_without_appointment_promotes_and_assigns_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('ready-no-appt@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: '7881953',
            product: 'MFS110',
        );
        $this->markSynced($incident->order_id);

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $this->freshIncident($incident);

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertTrue($this->isReadyEligible($fresh));
        $this->assertSame(OperationQueue::ActionRequired, $this->classify($fresh));
        $this->assertTrue($this->inReadyQueue($fresh));
        $this->assertFalse($this->inScheduledQueue($fresh));
    }

    public function test_validation_pass_with_appointment_keeps_owner_and_shows_both_queues(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('ready-with-appt@radium.local');
        $agent = $this->createAgent('jyotsana-appt@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: '7881953',
            product: 'MFS110',
            assignee: $agent,
        );
        $appointment = $this->createScheduledAppointment($incident);
        $this->markSynced($incident->order_id);

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $this->freshIncident($incident);

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id, 'Incident assignee must stay unchanged');
        $this->assertTrue($fresh->hasActiveSupportAppointment());
        $this->assertSame(SupportAppointmentStatus::Scheduled, $appointment->fresh()->status);
        $this->assertTrue($this->isReadyEligible($fresh));
        // Primary classifier priority unchanged.
        $this->assertSame(OperationQueue::Scheduled, $this->classify($fresh));
        // Dual membership: Ready overlay + Scheduled primary.
        $this->assertTrue($this->inScheduledQueue($fresh));
        $this->assertTrue($this->inReadyQueue($fresh));
        $this->assertNull($fresh->order->transaction_id);
    }

    public function test_validation_fail_with_appointment_does_not_promote_or_enter_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('fail-with-appt@radium.local');
        $agent = $this->createAgent('fail-agent@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: null,
            product: null,
            assignee: $agent,
        );
        $this->createScheduledAppointment($incident);

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $this->freshIncident($incident);

        $this->assertSame(IncidentStatus::AwaitingProductDetails, $fresh->status);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue($fresh->hasActiveSupportAppointment());
        $this->assertFalse($this->isReadyEligible($fresh));
        $this->assertSame(OperationQueue::Scheduled, $this->classify($fresh));
        $this->assertTrue($this->inScheduledQueue($fresh));
        $this->assertFalse($this->inReadyQueue($fresh));
    }

    public function test_serial_update_with_appointment_keeps_assignee_and_enters_ready_overlay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('serial-with-appt@radium.local');
        $agent = $this->createAgent('serial-agent@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: null,
            product: 'MFS110',
            assignee: $agent,
        );
        $this->createScheduledAppointment($incident);
        $this->markSynced($incident->order_id);

        app(OrderSerialService::class)->assignSerialNumber(
            $incident->order->fresh(),
            '7881953',
            $actor,
        );

        $fresh = $this->freshIncident($incident);

        $this->assertSame('7881953', $fresh->order->serial_number);
        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue($fresh->hasActiveSupportAppointment());
        $this->assertTrue($this->isReadyEligible($fresh));
        $this->assertSame(OperationQueue::Scheduled, $this->classify($fresh));
        $this->assertTrue($this->inReadyQueue($fresh));
        $this->assertTrue($this->inScheduledQueue($fresh));
    }

    public function test_agent_already_assigned_with_appointment_keeps_assignee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('agent-appt-admin@radium.local');
        $agent = $this->createAgent('agent-appt@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: '7881953',
            product: 'MFS110',
            assignee: $agent,
        );
        $appointment = $this->createScheduledAppointment($incident);
        $this->markSynced($incident->order_id);

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $this->freshIncident($incident);

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertNotSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertTrue($fresh->hasActiveSupportAppointment());
        $this->assertSame(SupportAppointmentStatus::Scheduled, $appointment->fresh()->status);
        $this->assertTrue($this->inReadyQueue($fresh));
        $this->assertTrue($this->inScheduledQueue($fresh));
    }

    public function test_unassigned_with_appointment_promotes_without_shift_admin_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('unassigned-appt@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        // Keep smart assignment off so support path does not claim an owner.
        config(['smart_assignment.enabled' => false]);

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: '7881953',
            product: 'MFS110',
        );
        $this->createScheduledAppointment($incident);
        $this->markSynced($incident->order_id);

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $this->freshIncident($incident);

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertNull($fresh->assigned_to_user_id);
        $this->assertTrue($fresh->hasActiveSupportAppointment());
        $this->assertTrue($this->isReadyEligible($fresh));
        $this->assertSame(OperationQueue::Scheduled, $this->classify($fresh));
        $this->assertTrue($this->inReadyQueue($fresh));
        $this->assertTrue($this->inScheduledQueue($fresh));
    }

    public function test_service_reference_removes_ready_overlay_while_classifier_completed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('sr-with-appt@radium.local');
        $agent = $this->createAgent('sr-agent@radium.local');
        $this->configureShiftAdmin($admin->id);
        $actor = User::factory()->create();

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $actor,
            serial: '7881953',
            product: 'MFS110',
            assignee: $agent,
        );
        $appointment = $this->createScheduledAppointment($incident);
        $this->markSynced($incident->order_id);

        app(ServiceCaseAssignmentEligibilityService::class)
            ->evaluateAssignmentEligibility($incident->order->fresh(), $actor);

        $fresh = $this->freshIncident($incident);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue($this->inReadyQueue($fresh));
        $this->assertTrue($this->inScheduledQueue($fresh));

        app(OrderTransactionService::class)->assignTransactionId(
            order: $fresh->order,
            transactionId: 'TXN-APPT-READY-1',
            actor: $admin,
            broadcast: false,
        );

        app(DashboardSnapshotStore::class)->forget();

        $order = $fresh->order->fresh();
        $completed = Incident::query()->with(['order', 'assignee', 'supportAppointments'])->findOrFail($incident->id);

        $this->assertSame('TXN-APPT-READY-1', $order->transaction_id);
        $this->assertSame(IncidentStatus::Closed, $completed->status);
        $this->assertSame($agent->id, $completed->assigned_to_user_id, 'Assignee unchanged through Service Reference');
        $this->assertFalse($this->isReadyEligible($completed));
        $this->assertFalse($this->inReadyQueue($completed));
        // Existing appointment lifecycle: close completes scheduled appointments.
        $this->assertSame(SupportAppointmentStatus::Completed, $appointment->fresh()->status);
    }

    public function test_ready_overlay_is_dashboard_membership_not_classifier_match(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('match-overlay@radium.local');
        $agent = $this->createAgent('match-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createCashfreeAwaitingIncident(
            actor: $admin,
            serial: '7881953',
            product: 'MFS110',
            assignee: $agent,
            status: IncidentStatus::Open,
        );
        $this->createScheduledAppointment($incident);
        $this->markSynced($incident->order_id);

        $fresh = $this->freshIncident($incident);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(OperationQueue::Scheduled, $classifier->classify($fresh));
        $this->assertTrue($classifier->matchesQueue($fresh, OperationQueue::Scheduled));
        // Classifier answers primary queue only — Ready overlay is not a classifier match.
        $this->assertFalse($classifier->matchesQueue($fresh, OperationQueue::ActionRequired));
        // Dashboard Snapshot owns Ready worklist membership.
        $this->assertTrue($this->inReadyQueue($fresh));
        $this->assertTrue($this->inScheduledQueue($fresh));
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertFalse(
            app(ServiceCaseAssignmentService::class)->shouldRemoveFromAdminReadyQueue($fresh),
        );
    }

    private function createCashfreeAwaitingIncident(
        User $actor,
        ?string $serial,
        ?string $product,
        ?User $assignee = null,
        IncidentStatus $status = IncidentStatus::AwaitingProductDetails,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => 'RD-CF-APPT-'.uniqid(),
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
            'status' => $status,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function createScheduledAppointment(Incident $incident): SupportAppointment
    {
        return SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9668152713',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);
    }

    private function markSynced(int $orderId): void
    {
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($orderId, [
            'lookup_result' => 'data_received',
        ]);
    }

    private function freshIncident(Incident $incident): Incident
    {
        return $incident->fresh([
            'order',
            'assignee.roles',
            'supportAppointments',
            'activeWaitingState',
        ]);
    }

    private function isReadyEligible(Incident $incident): bool
    {
        return app(ServiceCaseAssignmentEligibilityService::class)
            ->isReadyForReferenceEntry($incident->order, $incident);
    }

    private function classify(Incident $incident): OperationQueue
    {
        return app(OperationsQueueClassifier::class)->classify($incident);
    }

    private function inReadyQueue(Incident $incident): bool
    {
        app(DashboardSnapshotStore::class)->forget();

        return DashboardSnapshot::load()
            ->incidentsForQueue(OperationQueue::ActionRequired->value)
            ->contains(fn (Incident $row): bool => $row->id === $incident->id);
    }

    private function inScheduledQueue(Incident $incident): bool
    {
        app(DashboardSnapshotStore::class)->forget();

        return DashboardSnapshot::load()
            ->incidentsForQueue(OperationQueue::Scheduled->value)
            ->contains(fn (Incident $row): bool => $row->id === $incident->id);
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

    private function createAgent(string $email): User
    {
        $agent = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
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
