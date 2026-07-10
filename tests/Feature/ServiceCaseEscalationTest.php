<?php

namespace Tests\Feature;

use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseEscalationService;
use App\Services\ServiceCaseReopenService;
use App\Services\ServiceCaseStatusService;
use App\Services\SupportAppointmentService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ServiceCaseEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
            'service_case_assignment.escalation.level_1_email' => 'shubhanshi@radiumbox.com',
            'service_case_assignment.escalation.level_2_email' => 'shipra@radiumbox.com',
            'smart_assignment.enabled' => true,
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.callback_schedule.name' => 'callback_schedule',
            'interakt.templates.callback_schedule.language_code' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_agent_escalation_routes_to_escalation_specialist(): void
    {
        $agent = $this->createAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $incident = $this->createIncident($agent, assignedTo: $agent);

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident,
            actor: $agent,
            reason: 'Customer issue unresolved after troubleshooting.',
        );

        $this->assertSame($specialist->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_escalation_creates_audit_timeline_event_with_previous_assignee(): void
    {
        $agent = $this->createAgent('agent@test.com', 'Support Agent');
        $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $incident = $this->createIncident($agent, assignedTo: $agent);

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident,
            actor: $agent,
            reason: 'Needs supervisor intervention.',
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.escalated',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        $auditLog = AuditLog::query()
            ->where('event', 'service_case.escalated')
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($agent->id, $auditLog->new_values['previous_assigned_to_user_id'] ?? null);
        $this->assertSame('Needs supervisor intervention.', $auditLog->new_values['reason'] ?? null);
    }

    public function test_escalation_uses_ira_notification_policy_during_working_hours(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 201],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $this->createWorkSchedule($specialist);
        $incident = $this->createIncident($agent, assignedTo: $agent);

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident,
            actor: $agent,
            reason: 'Escalating during working hours.',
        );

        $notification = IraNotification::query()
            ->where('user_id', $specialist->id)
            ->where('notification_type', IraNotificationType::Reassignment->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Sent, $notification->status);
        Http::assertSentCount(1);
    }

    public function test_escalation_suppresses_telegram_outside_working_hours(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $this->createWorkSchedule($specialist);
        $incident = $this->createIncident($agent, assignedTo: $agent);

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident,
            actor: $agent,
            reason: 'Escalating after hours.',
        );

        $this->assertSame($specialist->id, $incident->fresh()->assigned_to_user_id);

        $notification = IraNotification::query()
            ->where('user_id', $specialist->id)
            ->where('notification_type', IraNotificationType::Reassignment->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Skipped, $notification->status);
        Http::assertNothingSent();
    }

    public function test_escalation_specialist_remains_excluded_from_normal_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $specialist = $this->createEligibleEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');

        $pool = app(ServiceCaseAssignmentService::class)->activeSupportAgents();
        $candidates = app(SmartAssignmentService::class)->eligibleCandidates();

        $this->assertCount(1, $pool);
        $this->assertContains($agent->id, collect($pool)->pluck('id'));
        $this->assertNotContains($specialist->id, collect($pool)->pluck('id'));
        $this->assertNotContains($specialist->id, collect($candidates)->pluck('id'));
    }

    public function test_workspace_escalate_action_transfers_assignment(): void
    {
        $agent = $this->createAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $incident = $this->createIncident($agent, assignedTo: $agent);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Escalate->value,
                'body' => 'Unable to resolve fingerprint driver issue.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($specialist->id, $incident->fresh()->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.escalated',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_escalated_case_retains_escalation_owner_after_customer_response_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $incident = $this->createIncident($agent, assignedTo: $agent);
        $this->enableNotificationChannels();

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident,
            actor: $agent,
            reason: 'Needs supervisor intervention.',
        );

        $escalationAudit = AuditLog::query()
            ->where('event', 'service_case.escalated')
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertNotNull($escalationAudit);
        $this->assertSame($specialist->id, (int) ($escalationAudit->new_values['assigned_to_user_id'] ?? 0));
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->hasActiveEscalationOwnership($incident->fresh(['assignee'])));

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-escalation-retain'], 200),
        ]);
        Mail::fake();

        $this->actingAs($specialist)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        app(SupportAppointmentService::class)->book($incident->fresh(), [
            'preferred_date' => '2026-07-10',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9123456782',
        ]);

        $freshIncident = $incident->fresh(['assignee', 'supportAppointments', 'activeWaitingState']);

        $this->assertSame($specialist->id, $freshIncident->assigned_to_user_id);
        $this->assertNotSame($agent->id, $freshIncident->assigned_to_user_id);
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->hasActiveEscalationOwnership($freshIncident));
    }

    public function test_reopened_old_escalation_case_follows_normal_assignment_on_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $incident = $this->createIncident($agent, assignedTo: $agent);
        $this->enableNotificationChannels();

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident,
            actor: $agent,
            reason: 'Needs supervisor intervention.',
        );

        app(ServiceCaseStatusService::class)->updateStatus($incident->fresh(), IncidentStatus::Closed, $agent);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.status_changed',
            'auditable_id' => $incident->id,
        ]);

        app(ServiceCaseReopenService::class)->reopen(
            incident: $incident->fresh(),
            actor: $specialist,
            body: 'Customer returned after closure.',
        );

        $this->assertFalse(app(ServiceCaseAssignmentService::class)->hasActiveEscalationOwnership($incident->fresh(['assignee'])));

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-reopened-escalation'], 200),
        ]);
        Mail::fake();

        app(SupportAppointmentService::class)->book($incident->fresh(), [
            'preferred_date' => '2026-07-10',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9123456782',
        ]);

        $freshIncident = $incident->fresh(['assignee', 'supportAppointments']);

        $this->assertSame($agent->id, $freshIncident->assigned_to_user_id);
        $this->assertNotSame($specialist->id, $freshIncident->assigned_to_user_id);
        $this->assertFalse(app(ServiceCaseAssignmentService::class)->hasActiveEscalationOwnership($freshIncident));
    }

    public function test_level_2_target_is_prepared_for_future_escalation(): void
    {
        $shipra = User::factory()->create([
            'name' => 'Shipra Kumari',
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $shipra->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $target = app(ServiceCaseEscalationService::class)->resolveLevel2Target();

        $this->assertNotNull($target);
        $this->assertSame($shipra->id, $target->id);
    }

    private function createAgent(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createEligibleAgent(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createEscalationSpecialist(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'telegram_chat_id' => '123456789',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        return $user;
    }

    private function createEligibleEscalationSpecialist(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createWorkSchedule(User $user): TeamMemberWorkSchedule
    {
        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
    }

    private function createIncident(User $creator, ?User $assignedTo = null): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-ESC-'.uniqid(),
            'serial_number' => 'SN-ESC-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Escalation Customer',
            'customer_email' => 'escalation@example.com',
            'customer_phone' => '9123456782',
            'transaction_id' => 'TXN-ESC-1',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Escalation test case',
            'description' => 'Escalation test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignedTo?->id,
        ]);

        app(\App\Services\RemarkService::class)->createForRemarkable(
            remarkable: $incident,
            actor: $creator,
            body: 'Initial escalation test remark.',
        );

        return $incident;
    }

    private function enableNotificationChannels(): void
    {
        foreach ([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ] as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );
            app(SystemSettingsService::class)->forget($key);
        }
    }
}
