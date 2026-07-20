<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Data\NotificationMessage;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\InteraktOutboundOutboxWriter;
use App\Services\Interakt\WhatsAppTemplateDispatcher;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamAvailabilityOverviewService;
use App\Services\Operations\TeamAvailabilityService;
use App\Services\Operations\TeamMemberActivityService;
use App\Services\RemarkService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_team_member_can_update_availability(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->patch(route('profile.availability.update'), [
                'availability_status' => TeamAvailabilityStatus::Busy->value,
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'availability-updated');

        $agent->refresh();

        $this->assertSame(TeamAvailabilityStatus::Busy, $agent->availability_status);
        $this->assertNotNull($agent->availability_updated_at);
        $this->assertNull($agent->leave_start_date);
        $this->assertNull($agent->leave_end_date);
    }

    public function test_team_member_cannot_set_on_leave_status(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->patch(route('profile.availability.update'), [
                'availability_status' => 'on_leave',
                'leave_start_date' => '2026-07-10',
                'leave_end_date' => '2026-07-20',
            ])
            ->assertSessionHasErrors('availability_status');

        $agent->refresh();

        $this->assertNotSame('on_leave', $agent->availability_status?->value ?? $agent->getRawOriginal('availability_status'));
    }

    public function test_profile_team_availability_shows_request_leave_link(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Request Leave')
            ->assertDontSee('Leave start date')
            ->assertDontSee('On Leave');
    }

    public function test_non_team_member_cannot_update_availability(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->patch(route('profile.availability.update'), [
                'availability_status' => TeamAvailabilityStatus::Available->value,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_view_team_availability_on_operations_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create(['name' => 'Avinash Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
        app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule']));

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'team']))
            ->assertOk()
            ->assertSee('Avinash Agent', false)
            ->assertSee('Available', false)
            ->assertSee('Team Presence', false);

        Carbon::setTestNow();
    }

    public function test_db_available_without_session_shows_effective_offline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);

        $snapshot = app(TeamAvailabilityOverviewService::class)->memberSnapshot($agent);

        $this->assertSame('available', $snapshot['availability']['stored_status']);
        $this->assertSame('Available', $snapshot['availability']['stored_label']);
        $this->assertSame('offline', $snapshot['availability']['status']);
        $this->assertSame('Offline', $snapshot['availability']['label']);
        $this->assertFalse($snapshot['on_duty']);

        Carbon::setTestNow();
    }

    public function test_active_session_user_appears_on_duty_in_team_overview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        app(PresenceEngineService::class)->startSession($agent);

        $members = app(TeamAvailabilityOverviewService::class)->members();

        $this->assertCount(1, $members);
        $this->assertSame($agent->id, $members[0]['id']);
        $this->assertTrue($members[0]['on_duty']);
        $this->assertSame('Available', $members[0]['availability']['label']);

        Carbon::setTestNow();
    }

    public function test_admin_user_appears_in_team_overview_when_on_duty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        TeamMemberWorkSchedule::query()->create([
            'user_id' => $admin->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
        app(PresenceEngineService::class)->startSession($admin->fresh(['workSchedule']));

        $members = app(TeamAvailabilityOverviewService::class)->members();

        $this->assertCount(1, $members);
        $this->assertSame($admin->id, $members[0]['id']);
        $this->assertTrue($members[0]['on_duty']);

        Carbon::setTestNow();
    }

    public function test_superadmin_user_excluded_from_team_overview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $owner = User::factory()->create(['name' => 'Owner']);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertSame([], app(TeamAvailabilityOverviewService::class)->members());

        Carbon::setTestNow();
    }

    public function test_on_duty_member_count_matches_workforce_authority(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $onDutyAgent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        app(PresenceEngineService::class)->startSession($onDutyAgent);

        $offlineAgent = $this->createScheduledAgent(TeamAvailabilityStatus::Offline, 'Offline Agent');

        $overview = app(TeamAvailabilityOverviewService::class);
        $authority = app(\App\Services\Operations\WorkforceAuthorityService::class);

        $expectedOnDuty = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', RolePermissionSeeder::SUPPORT_TEAM_ROLES))
            ->get()
            ->filter(fn (User $user): bool => $authority->isOnDuty($user))
            ->count();

        $this->assertSame($expectedOnDuty, count($overview->members()));
        $this->assertSame($onDutyAgent->id, $overview->members()[0]['id']);
        $this->assertFalse($authority->isOnDuty($offlineAgent));

        Carbon::setTestNow();
    }

    public function test_whatsapp_dispatch_updates_customer_communication_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 12:00:00'));

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.display_name' => 'Order Update',
            'interakt.templates.request_serial_number.language_code' => 'en',
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TA-WA',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'WhatsApp activity case',
            'description' => 'WhatsApp activity case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-team-availability-001'], 200),
        ]);

        app(WhatsAppTemplateDispatcher::class)->dispatch(
            template: \App\Enums\WhatsAppTemplate::RequestSerialNumber,
            incident: $incident,
            actor: $agent,
            triggerSource: \App\Enums\WhatsAppTemplateTriggerSource::Manual,
        );

        $agent->refresh();

        $this->assertNotNull($agent->last_customer_communication_at);
        $this->assertSame(
            '2026-07-05T12:00:00+05:30',
            $agent->last_customer_communication_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    public function test_email_notification_updates_customer_communication_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 13:00:00'));

        config([
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TA-EMAIL',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Email activity case',
            'description' => 'Email activity case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $message = new NotificationMessage(
            type: NotificationType::RequestSerialNumber,
            customer: $order,
            incident: $incident,
            actor: $agent,
        );

        $result = app(EmailChannel::class)->send($message);

        $this->assertTrue($result->success);
        $this->assertSame(NotificationChannelType::Email, $result->channel);

        $agent->refresh();

        $this->assertNotNull($agent->last_customer_communication_at);
        $this->assertSame(
            '2026-07-05T13:00:00+05:30',
            $agent->last_customer_communication_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    public function test_existing_case_action_activity_tracking_still_works(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 14:00:00'));

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TA-ACT',
            'serial_number' => 'SN-TA-ACT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Activity tracking case',
            'description' => 'Activity tracking case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        app(RemarkService::class)->createForRemarkable(
            $incident,
            $actor,
            'Followed up with customer.',
        );

        $actor->refresh();

        $this->assertNotNull($actor->last_case_action_at);
        $this->assertNotNull($actor->last_active_at);

        $snapshot = app(TeamMemberActivityService::class)->snapshotFor($actor);
        $this->assertNotNull($snapshot['last_case_action_at']);
        $this->assertNotNull($snapshot['last_work_activity_at']);
        $this->assertSame('Case Updated', $snapshot['primary_work_activity_label']);

        Carbon::setTestNow();
    }

    public function test_profile_shows_team_availability_form_for_operational_users(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Team Availability')
            ->assertSee('Update availability');
    }

    public function test_agent_cannot_self_set_offline_during_active_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        app(PresenceEngineService::class)->startSession($agent);

        $this->actingAs($agent)
            ->patch(route('profile.availability.update'), [
                'availability_status' => TeamAvailabilityStatus::Offline->value,
            ])
            ->assertSessionHasErrors('availability_status');

        $agent->refresh();

        $this->assertSame(TeamAvailabilityStatus::Available, $agent->availability_status);

        Carbon::setTestNow();
    }

    public function test_profile_hides_offline_option_during_active_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        app(PresenceEngineService::class)->startSession($agent);

        $this->actingAs($agent)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Available', false)
            ->assertSee('Busy', false)
            ->assertDontSee('>Offline</option>', false)
            ->assertSee('While you are on duty', false);

        Carbon::setTestNow();
    }

    public function test_logout_still_sets_offline_and_audits_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available, password: true);
        app(PresenceEngineService::class)->startSession($agent);

        $this->actingAs($agent)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $agent->refresh();

        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.availability_changed',
            'auditable_type' => User::class,
            'auditable_id' => $agent->id,
        ]);

        $auditLog = AuditLog::query()
            ->where('event', 'user.availability_changed')
            ->where('auditable_id', $agent->id)
            ->latest('id')
            ->first();

        $this->assertSame('available', $auditLog?->old_values['status'] ?? null);
        $this->assertSame('offline', $auditLog?->new_values['status'] ?? null);
        $this->assertSame('logout', $auditLog?->new_values['source'] ?? null);

        Carbon::setTestNow();
    }

    public function test_timeout_still_sets_offline_and_audits_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        config(['presence.away_timeout_minutes' => 15]);

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        $session = app(PresenceEngineService::class)->startSession($agent);
        $session?->update(['last_activity_at' => now()->subMinutes(16)]);

        app(PresenceEngineService::class)->processTimedOutSessions();

        $agent->refresh();
        $session?->refresh();

        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
        $this->assertSame(WorkSessionEndReason::AwayTimeout, $session?->ended_reason);

        $auditLog = AuditLog::query()
            ->where('event', 'user.availability_changed')
            ->where('auditable_id', $agent->id)
            ->latest('id')
            ->first();

        $this->assertSame('timeout', $auditLog?->new_values['source'] ?? null);

        Carbon::setTestNow();
    }

    public function test_manual_availability_change_creates_audit_log(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->patch(route('profile.availability.update'), [
                'availability_status' => TeamAvailabilityStatus::Busy->value,
            ])
            ->assertRedirect(route('profile.edit'));

        $auditLog = AuditLog::query()
            ->where('event', 'user.availability_changed')
            ->where('auditable_id', $agent->id)
            ->latest('id')
            ->first();

        $this->assertSame('manual', $auditLog?->new_values['source'] ?? null);
        $this->assertSame('busy', $auditLog?->new_values['status'] ?? null);
    }

    public function test_expected_unavailable_agents_visible_to_managers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $onDutyAgent = $this->createScheduledAgent(TeamAvailabilityStatus::Available, 'On Duty Agent');
        app(PresenceEngineService::class)->startSession($onDutyAgent);

        $loggedOutAgent = $this->createScheduledAgent(TeamAvailabilityStatus::Offline, 'Logged Out Agent');
        $session = app(PresenceEngineService::class)->startSession($loggedOutAgent);
        app(PresenceEngineService::class)->closeSession($loggedOutAgent, WorkSessionEndReason::ManualLogout);
        $this->assertNotNull($session?->fresh()?->logout_at);

        $overview = app(TeamAvailabilityOverviewService::class)->overview();

        $this->assertCount(1, $overview['on_duty']);
        $this->assertSame($onDutyAgent->id, $overview['on_duty'][0]['id']);
        $this->assertCount(1, $overview['unavailable']);
        $this->assertSame($loggedOutAgent->id, $overview['unavailable'][0]['id']);
        $this->assertSame('Logged out during shift', $overview['unavailable'][0]['unavailability_label']);
        $this->assertSame(1, $overview['unavailable'][0]['session_summary']['manual_logout_count']);

        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'team']))
            ->assertOk()
            ->assertSee('Expected today but unavailable', false)
            ->assertSee('Logged Out Agent', false)
            ->assertSee('Logged out during shift', false);

        Carbon::setTestNow();
    }

    public function test_session_start_does_not_promote_user_on_approved_leave_to_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Offline);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        app(PresenceEngineService::class)->startSession($agent);

        $agent->refresh();

        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
    }

    public function test_session_start_does_not_promote_user_outside_working_hours_to_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 19:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Offline);

        app(PresenceEngineService::class)->startSession($agent);

        $agent->refresh();

        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
    }

    private function createScheduledAgent(
        TeamAvailabilityStatus $status,
        string $name = 'Scheduled Agent',
        bool $password = false,
    ): User {
        $attributes = [
            'name' => $name,
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ];

        if ($password) {
            $attributes['password'] = bcrypt('password');
        }

        $user = User::factory()->create($attributes);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }
}
