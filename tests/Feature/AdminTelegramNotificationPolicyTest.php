<?php

namespace Tests\Feature;

use App\Data\Operations\IraCommunicationInput;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IraRiskCategory;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraBriefingFormatter;
use App\Services\Operations\IraCommunicationService;
use App\Services\RefundNotificationService;
use App\Support\Telegram\TelegramOperationalLinkFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminTelegramNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
            'ira.communication.admin_quiet_hours.enabled' => true,
            'ira.communication.admin_quiet_hours.start' => '18:30',
            'ira.communication.admin_quiet_hours.end' => '09:00',
            'app.schedule_timezone' => 'Asia/Kolkata',
        ]);

        $this->enableTelegramNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_team_availability_issue_suppressed_at_1830(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 18:30:00', 'Asia/Kolkata'));

        Http::fake();

        $opsAdmin = $this->createOpsAdmin('810001');
        $results = app(IraCommunicationService::class)->sendRiskAlerts($this->briefingWithLowStaffing());

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::TeamAvailabilityIssue->value,
        ]);
    }

    public function test_admin_team_availability_issue_suppressed_overnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 02:00:00', 'Asia/Kolkata'));

        Http::fake();

        $opsAdmin = $this->createOpsAdmin('810002');
        $results = app(IraCommunicationService::class)->sendRiskAlerts($this->briefingWithLowStaffing());

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::TeamAvailabilityIssue->value,
        ]);
    }

    public function test_admin_unassigned_scheduled_work_suppressed_overnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 03:00:00', 'Asia/Kolkata'));

        Http::fake();

        $opsAdmin = $this->createOpsAdmin('810003');
        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::UnassignedScheduledWork,
            context: [
                'unassigned_scheduled' => 2,
                'dedupe_key' => 'unassigned_scheduled',
                'message' => '2 scheduled case(s) have no assignee.',
            ],
        ));

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::UnassignedScheduledWork->value,
        ]);
    }

    public function test_admin_routine_alerts_resume_after_0900(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $opsAdmin = $this->createOpsAdmin('810004');
        $results = app(IraCommunicationService::class)->sendRiskAlerts($this->briefingWithLowStaffing());

        $this->assertNotSame([], $results);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::TeamAvailabilityIssue->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);
    }

    public function test_morning_digest_is_scheduled_for_1000_ist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 10:00:00', 'Asia/Kolkata'));

        $event = $this->findScheduledEvent('ira:send-ops-digest --period=morning');

        $this->assertTrue($event->isDue($this->app));
        $this->assertSame('10:00', config('ira.communication.admin_ops_digest.morning_time'));
    }

    public function test_evening_digest_is_scheduled_for_2030_ist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 20:30:00', 'Asia/Kolkata'));

        $event = $this->findScheduledEvent('ira:send-ops-digest --period=evening');

        $this->assertTrue($event->isDue($this->app));
        $this->assertSame('20:30', config('ira.communication.admin_ops_digest.evening_time'));
    }

    public function test_same_period_digest_cannot_duplicate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $opsAdmin = $this->createOpsAdmin('810005');
        $briefing = $this->sampleBriefing();
        $service = app(IraCommunicationService::class);

        $first = $service->sendOpsDigest($opsAdmin, $briefing, 'morning');
        $second = $service->sendOpsDigest($opsAdmin, $briefing, 'morning');

        $this->assertCount(1, $first);
        $this->assertSame(IraNotificationStatus::Sent, $first[0]->status);
        $this->assertSame([], $second);
        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $opsAdmin->id)
                ->where('notification_type', IraNotificationType::OpsDigest->value)
                ->count(),
        );
    }

    public function test_morning_summary_includes_late_login_information(): void
    {
        $message = app(IraBriefingFormatter::class)->formatOpsDigest(
            briefing: $this->sampleBriefing(),
            period: 'morning',
            digestContext: $this->digestContextWithLateArrival(),
        );

        $this->assertStringContainsString('Late arrivals', $message);
        $this->assertStringContainsString('Late Agent', $message);
        $this->assertStringContainsString('37 min', $message);
        $this->assertStringContainsString('login 10:37', $message);
    }

    public function test_evening_summary_includes_attendance_information(): void
    {
        $message = app(IraBriefingFormatter::class)->formatOpsDigest(
            briefing: $this->sampleBriefing(),
            period: 'evening',
            digestContext: $this->digestContextWithLateArrival(),
        );

        $this->assertStringContainsString('Present:', $message);
        $this->assertStringContainsString('Absent:', $message);
        $this->assertStringContainsString('On leave:', $message);
        $this->assertStringContainsString('Evening Operations Summary', $message);
    }

    public function test_refund_aggregates_appear_in_both_summaries(): void
    {
        $digestContext = $this->digestContextWithLateArrival();
        $digestContext['refunds'] = [
            'pending_approval' => 2,
            'pending_execution' => 1,
            'submitted_today' => 3,
        ];

        $morning = app(IraBriefingFormatter::class)->formatOpsDigest(
            briefing: $this->sampleBriefing(),
            period: 'morning',
            digestContext: $digestContext,
        );

        $evening = app(IraBriefingFormatter::class)->formatOpsDigest(
            briefing: $this->sampleBriefing(),
            period: 'evening',
            digestContext: $digestContext,
        );

        $this->assertStringContainsString('Refunds:', $morning);
        $this->assertStringContainsString('2 pending approval', $morning);
        $this->assertStringContainsString('Refunds:', $evening);
        $this->assertStringContainsString('3 submitted today', $evening);
    }

    public function test_admin_assignment_sends_immediately_outside_normal_work_hours(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $admin = $this->createOpsAdmin('810006');
        $incident = $this->createOpenIncident('RD-ADMIN-ASSIGN');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::ManualAssignment,
            context: [
                'user_id' => $admin->id,
                'incident_id' => $incident->id,
                'case' => $incident->reference_no,
                'customer' => 'Customer',
                'device' => 'Device',
                'time' => '22:00',
                'dedupe_key' => 'assignment:test:'.$admin->id,
            ],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        Http::assertSentCount(1);
    }

    public function test_ira_ready_queue_assignment_to_admin_does_not_send_individual_telegram(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));

        $admin = $this->createOpsAdmin('810013');
        $incident = $this->createOpenIncident('RD-IRA-READY-ASSIGN');

        $results = app(IraCommunicationService::class)->sendManualAssignment(
            assignee: $admin,
            customer: 'Dimpal Deka',
            device: 'MFS110',
            time: 'Unscheduled',
            caseReference: $incident->reference_no,
            context: [
                'incident_id' => $incident->id,
                'assigned_by' => 'IRA',
                'override_reason' => 'shift_admin',
                'task' => 'General',
            ],
        );

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $admin->id,
            'notification_type' => IraNotificationType::ManualAssignment->value,
        ]);
    }

    public function test_ira_hardware_routing_assignment_to_admin_still_sends_telegram(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));

        $admin = $this->createOpsAdmin('810015');
        $incident = $this->createOpenIncident('RD-IRA-HARDWARE-ASSIGN');

        $results = app(IraCommunicationService::class)->sendManualAssignment(
            assignee: $admin,
            customer: 'Dimpal Deka',
            device: 'MFS110',
            time: 'Unscheduled',
            caseReference: $incident->reference_no,
            context: [
                'incident_id' => $incident->id,
                'assigned_by' => 'IRA',
                'override_reason' => 'hardware_routing',
                'task' => 'General',
            ],
        );

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        $this->assertStringContainsString('New support assigned', $results[0]->message);
        Http::assertSentCount(1);
    }

    public function test_human_admin_assignment_telegram_still_sends(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 11:00:00', 'Asia/Kolkata'));

        $admin = $this->createOpsAdmin('810014');
        $incident = $this->createOpenIncident('RD-HUMAN-ADMIN-ASSIGN');

        $results = app(IraCommunicationService::class)->sendManualAssignment(
            assignee: $admin,
            customer: 'Dimpal Deka',
            device: 'MFS110',
            time: 'Unscheduled',
            caseReference: $incident->reference_no,
            context: [
                'incident_id' => $incident->id,
                'assigned_by' => 'Ravi (Admin)',
                'task' => 'General',
            ],
        );

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        $this->assertStringContainsString('New support assigned', $results[0]->message);
        Http::assertSentCount(1);
    }

    public function test_admin_reassignment_sends_immediately_outside_normal_work_hours(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $admin = $this->createOpsAdmin('810007');
        $incident = $this->createOpenIncident('RD-ADMIN-REASSIGN');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::Reassignment,
            context: [
                'user_id' => $admin->id,
                'incident_id' => $incident->id,
                'case' => $incident->reference_no,
                'customer' => 'Customer',
                'device' => 'Device',
                'time' => '22:00',
                'dedupe_key' => 'reassignment:test:'.$admin->id,
            ],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        Http::assertSentCount(1);
    }

    public function test_agent_assignment_behavior_remains_unchanged_outside_hours(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create([
            'name' => 'Night Agent',
            'telegram_chat_id' => '810008',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'effective_from' => '2026-01-01',
            'work_start_time' => '10:00:00',
            'work_end_time' => '18:30:00',
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $incident = $this->createOpenIncident('RD-ADMIN-ASSIGN');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::ManualAssignment,
            context: [
                'user_id' => $agent->id,
                'incident_id' => $incident->id,
                'case' => $incident->reference_no,
                'customer' => 'Customer',
                'device' => 'Device',
                'time' => '22:00',
                'dedupe_key' => 'assignment:agent:'.$agent->id,
            ],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Skipped, $results[0]->status);
        Http::assertNothingSent();
    }

    public function test_super_admin_risk_refund_policy_remains_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:00:00', 'Asia/Kolkata'));

        Http::fake();

        $owner = User::factory()->create([
            'telegram_chat_id' => '810009',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $results = app(IraCommunicationService::class)->sendRiskAlerts($this->briefingWithSlaDanger());

        $this->assertSame([], $results);
        Http::assertNothingSent();

        $refund = RefundRequest::query()->create([
            'order_id' => $this->createOrder()->id,
            'reference_no' => 'REF-ADMIN-POLICY-1',
            'amount' => 100,
            'reason' => 'Policy test refund.',
            'status' => RefundStatus::Pending,
            'requested_by' => User::factory()->create()->id,
        ]);

        app(RefundNotificationService::class)->notifyApproversOfSubmission($refund);

        Http::assertNothingSent();
    }

    public function test_watchdog_routing_remains_unchanged(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $owner = User::factory()->create([
            'telegram_chat_id' => '810009',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $opsAdmin = $this->createOpsAdmin('810009b');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::CriticalSystemAlert,
            context: [
                'label' => 'Queue',
                'message' => '3 failed job(s) in dead-letter queue.',
                'affected_count' => 3,
                'dedupe_key' => 'watchdog:queue:dead_letter',
            ],
        ));

        $this->assertCount(1, $results);
        $this->assertSame($owner->id, $results[0]->user_id);
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::CriticalSystemAlert->value,
        ]);
    }

    public function test_case_deep_link_is_generated_only_when_authorized(): void
    {
        $admin = $this->createOpsAdmin('810010');
        $incident = $this->createOpenIncident('RD-ADMIN-ASSIGN');
        $formatter = app(TelegramOperationalLinkFormatter::class);

        $this->assertNotNull($formatter->incidentLink($admin, $incident));

        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->assertNull($formatter->incidentLink($guest, $incident));
    }

    public function test_refund_deep_link_is_generated_only_when_authorized(): void
    {
        $admin = $this->createOpsAdmin('810011');
        $refund = RefundRequest::query()->create([
            'order_id' => $this->createOrder()->id,
            'reference_no' => 'REF-ADMIN-POLICY-2',
            'amount' => 100,
            'reason' => 'Policy test refund link.',
            'status' => RefundStatus::Pending,
            'requested_by' => User::factory()->create()->id,
        ]);
        $formatter = app(TelegramOperationalLinkFormatter::class);

        $this->assertNotNull($formatter->refundLink($admin, $refund));

        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->assertNull($formatter->refundLink($guest, $refund));
    }

    public function test_order_deep_link_is_generated_only_when_authorized(): void
    {
        $admin = $this->createOpsAdmin('810012');
        $order = $this->createOrder();
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Policy test order link case',
            'description' => 'Policy test order link case.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $formatter = app(TelegramOperationalLinkFormatter::class);

        $url = $formatter->orderLink($admin, $order);

        $this->assertNotNull($url);
        $this->assertSame(route('dashboard', [
            'open_customer_360' => $incident->id,
            'open_customer_360_reference' => $incident->display_reference,
        ], absolute: true), $url);

        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->assertNull($formatter->orderLink($guest, $order));
    }

    public function test_identifier_fallback_works_when_no_safe_link_exists(): void
    {
        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);
        $incident = $this->createOpenIncident('RD-ADMIN-ASSIGN');
        $formatter = app(TelegramOperationalLinkFormatter::class);

        $this->assertNull($formatter->linkLine(
            'Open Case',
            $formatter->incidentLink($guest, $incident),
        ));
    }

    public function test_assignment_telegram_uses_text_link_entity_for_case_reference(): void
    {
        config(['app.url' => 'https://desk.radiumbox.com']);
        URL::forceRootUrl('https://desk.radiumbox.com');
        URL::forceScheme('https');

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $admin = $this->createOpsAdmin('810014');
        $incident = $this->createOpenIncident('RD-ADMIN-ENTITY');

        app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::Reassignment,
            context: [
                'user_id' => $admin->id,
                'incident_id' => $incident->id,
                'case' => $incident->reference_no,
                'customer' => 'Mohammad Nesar',
                'device' => 'Device',
                'time' => '22:00',
                'dedupe_key' => 'reassignment:entity:'.$admin->id,
            ],
        ));

        Http::assertSent(function ($request) use ($incident): bool {
            $payload = $request->data();
            $entities = $payload['entities'] ?? [];

            return str_contains((string) ($payload['text'] ?? ''), 'Case: '.$incident->reference_no)
                && ! str_contains((string) ($payload['text'] ?? ''), 'Open Case:')
                && ! array_key_exists('parse_mode', $payload)
                && is_array($entities)
                && ($entities[0]['type'] ?? null) === 'text_link'
                && ($entities[0]['url'] ?? null) === route('incidents.show', $incident, absolute: true);
        });
    }

    public function test_operational_link_lines_use_bare_https_urls_not_markdown(): void
    {
        config(['app.url' => 'https://desk.radiumbox.com']);
        URL::forceRootUrl('https://desk.radiumbox.com');
        URL::forceScheme('https');

        $admin = $this->createOpsAdmin('810013');
        $incident = $this->createOpenIncident('RD-ADMIN-LINK');
        $formatter = app(TelegramOperationalLinkFormatter::class);

        $caseUrl = $formatter->incidentLink($admin, $incident);
        $line = $formatter->linkLine('Open Case', $caseUrl);

        $this->assertNotNull($caseUrl);
        $this->assertStringStartsWith('https://', $caseUrl);
        $this->assertSame("Open Case: {$caseUrl}", $line);
        $this->assertStringNotContainsString('[Open Case](', (string) $line);
    }

    public function test_scheduler_timezone_remains_asia_kolkata(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.schedule_timezone'));

        $event = $this->findScheduledEvent('ira:send-ops-digest --period=morning');
        $this->assertSame('Asia/Kolkata', $event->timezone);
    }

    private function createOpsAdmin(string $chatId): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Admin',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function digestContextWithLateArrival(): array
    {
        return [
            'team' => [
                'present' => ['On Duty Agent'],
                'absent' => ['Absent Agent'],
                'on_leave' => ['Leave Agent'],
                'pending_leave_approvals' => 1,
                'late_arrivals' => [[
                    'name' => 'Late Agent',
                    'minutes_late' => 37,
                    'login_at' => '10:37',
                    'late_days_in_window' => 4,
                    'evaluated_days' => 10,
                ]],
                'attendance_shortfalls' => [[
                    'name' => 'Short Agent',
                    'shortfall_minutes' => 45,
                ]],
            ],
            'operations' => [
                'open_cases' => 12,
                'overdue' => 2,
                'warning' => 1,
                'waiting' => 5,
                'missed_appointments' => 1,
                'unassigned_scheduled' => 2,
                'unassigned_important' => 1,
                'escalations_pending' => 1,
            ],
            'refunds' => [
                'pending_approval' => 1,
                'pending_execution' => 1,
                'submitted_today' => 1,
            ],
            'overload_lines' => ['Agent A has 9 open cases.'],
        ];
    }

    private function sampleBriefing(): IraMorningBriefing
    {
        return new IraMorningBriefing(
            greeting: 'Good morning.',
            summary: 'Operations summary.',
            healthStatus: 'warning',
            highlights: [],
            risks: [],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: [
                    'open_cases' => 12,
                    'overdue' => 2,
                    'warning' => 1,
                    'waiting' => 5,
                    'missed_appointments' => 1,
                ],
                team: ['available' => 2],
                performance: [],
            ),
        );
    }

    private function createOpenIncident(string $orderId = 'RD-ADMIN-POLICY'): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'transaction_id' => null,
            'customer_name' => 'Policy Customer',
            'customer_email' => 'policy@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Policy test case',
            'description' => 'Policy test case.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createOrder(): Order
    {
        $creator = User::factory()->create(['is_active' => true]);

        return Order::query()->create([
            'order_id' => 'RD-ADMIN-LINK',
            'serial_number' => 'SN-ADMIN-LINK',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function briefingWithLowStaffing(): IraMorningBriefing
    {
        return new IraMorningBriefing(
            greeting: 'Good evening.',
            summary: 'Staffing risk.',
            healthStatus: 'warning',
            highlights: [],
            risks: [
                new IraOperationalRisk(
                    key: 'workload.low_staffing',
                    title: 'Low Staffing',
                    category: IraRiskCategory::Workload,
                    severity: AIRiskLevel::High,
                    message: 'Only 0 team member(s) available.',
                    context: ['available' => 0],
                ),
            ],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: ['open_cases' => 1],
                team: ['available' => 0],
                performance: [],
            ),
        );
    }

    private function briefingWithSlaDanger(): IraMorningBriefing
    {
        return new IraMorningBriefing(
            greeting: 'Good afternoon.',
            summary: 'SLA risk.',
            healthStatus: 'critical',
            highlights: [],
            risks: [
                new IraOperationalRisk(
                    key: 'customer.sla_danger',
                    title: 'SLA Danger',
                    category: IraRiskCategory::Customer,
                    severity: AIRiskLevel::High,
                    message: 'Cases overdue.',
                    context: ['overdue' => 3],
                ),
            ],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: ['overdue' => 3],
                team: ['available' => 2],
                performance: [],
            ),
        );
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function findScheduledEvent(string $needle): Event
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events());
        $event = $events->first(function (Event $event) use ($needle): bool {
            foreach ([
                (string) ($event->command ?? ''),
                (string) ($event->description ?? ''),
                (string) $event->getSummaryForDisplay(),
            ] as $haystack) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }

            return false;
        });

        $this->assertNotNull($event, "Scheduled event not found: {$needle}");

        return $event;
    }
}
