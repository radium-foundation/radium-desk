<?php

namespace Tests\Feature;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\WaitingReason;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Data\Operations\OperationsDashboardData;
use App\Models\SupportAppointment;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Operations\OperationsDashboardService;
use App\Services\Operations\OperationsSupportIntelligenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createAdminUser(string $email = 'admin-ops-dashboard@test.com'): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Admin',
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email = 'agent-ops-dashboard@test.com'): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Agent',
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    public function test_agent_cannot_access_operations_dashboard(): void
    {
        $this->actingAs($this->createAgentUser())
            ->get(route('admin.operations.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_operations_dashboard(): void
    {
        $admin = $this->createAdminUser();
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-OPS-001',
            'customer_name' => 'Jane Customer',
            'serial_number' => 'FPSPL1141XX',
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
            'title' => 'Notification failure case',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        app(AuditLogService::class)->log(
            userId: $actor->id,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'request_serial_number',
                'source' => 'manual',
                'trigger_source' => 'manual',
                'aggregate_success' => false,
                'aggregate_message' => 'Notification failed',
                'channel_results' => [
                    [
                        'channel' => 'whatsapp',
                        'status' => 'failed',
                        'success' => false,
                        'retryable' => true,
                        'message' => 'Interakt API timeout',
                        'timestamp' => now()->toIso8601String(),
                        'duration_ms' => 1200,
                    ],
                ],
            ],
        );

        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'request_serial',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.test.1',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(5)->addSeconds(2),
            'metadata' => [
                'channel_results' => [
                    ['channel' => 'whatsapp', 'success' => true],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Operations Control Center')
            ->assertSee('Critical Alerts')
            ->assertSee('Command Center')
            ->assertSee('Integration Health')
            ->assertSee('System Health')
            ->assertSee('Operations Queue')
            ->assertSee('Support Today')
            ->assertSee('Team Load')
            ->assertSee('View Full Analysis')
            ->assertSee('Loading recommendations')
            ->assertSee('Cashfree')
            ->assertSee('id="operations-dashboard-tabs"', false)
            ->assertSee('operations-tab-team', false)
            ->assertSee('operations-tab-performance', false)
            ->assertSee('operations-tab-system', false)
            ->assertSee('operations-tab-today-content', false)
            ->assertDontSee('IRA Advisor')
            ->assertDontSee('Immediate Risks');
    }

    public function test_operations_dashboard_shows_interakt_template_configuration_health(): void
    {
        config(['interakt.api_key' => 'test-interakt-key']);

        foreach ([
            'request_serial_number' => 'order_confirm_manual_schedule',
            'repair_started' => 'repair_started',
            'repair_completed' => 'repair_completed',
            'ready_for_dispatch' => 'ready_for_dispatch',
            'refund_update' => 'refund_update',
            'amc_reminder' => 'amc_reminder',
            'support_appointment_booked' => 'support_appointment_booked',
        ] as $templateKey => $templateName) {
            config([
                'interakt.templates.'.$templateKey.'.enabled' => true,
                'interakt.templates.'.$templateKey.'.name' => $templateName,
                'interakt.templates.'.$templateKey.'.language_code' => 'en_US',
                'interakt.templates.'.$templateKey.'.language_code_is_default' => false,
            ]);
        }

        $response = $this->actingAs($this->createAdminUser('admin-ops-templates@test.com'))
            ->getJson(route('admin.operations.live', ['groups' => 'system']));

        $response->assertOk()
            ->assertSee('Interakt Template Configuration', false);

        $this->assertStringContainsString(
            '7 / 7 templates configured',
            (string) $response->json('html.system_tab'),
        );
    }

    public function test_operations_dashboard_shows_meta_flow_integration_card(): void
    {
        $this->actingAs($this->createAdminUser('admin-ops-meta-flow@test.com'))
            ->getJson(route('admin.operations.live', ['groups' => 'system']))
            ->assertOk()
            ->assertSee('Meta Flow', false)
            ->assertSee('Not Configured', false);
    }

    public function test_admin_can_refresh_operations_dashboard_live_payload(): void
    {
        $admin = $this->createAdminUser('admin-ops-live@test.com');

        $response = $this->actingAs($admin)
            ->getJson(route('admin.operations.live'));

        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'groups',
                'html' => [
                    'critical_alerts',
                    'overview_cards',
                    'ira_briefing_compact',
                    'health_status',
                    'today_tab',
                    'team_tab',
                    'performance_tab',
                    'system_tab',
                ],
            ]);
    }

    public function test_command_center_cards_render_on_initial_page(): void
    {
        $this->actingAs($this->createAdminUser('admin-command-cards@test.com'))
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('System Health', false)
            ->assertSee('Operations Queue', false)
            ->assertSee('Support Today', false)
            ->assertSee('Team Load', false)
            ->assertSee('operations-command-card', false);
    }

    public function test_healthy_integration_systems_render_collapsed_on_initial_page(): void
    {
        $this->actingAs($this->createAdminUser('admin-health-collapsed@test.com'))
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('operations-health-trigger-cashfree', false)
            ->assertSee('accordion-button collapsed', false)
            ->assertSee('Expand to load Cashfree details', false);
    }

    public function test_critical_alerts_still_render_on_initial_page(): void
    {
        $this->actingAs($this->createAdminUser('admin-critical-alerts@test.com'))
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Critical Alerts', false)
            ->assertSee('operations-critical-alerts', false);
    }

    public function test_live_endpoint_lazy_loads_tab_and_health_details(): void
    {
        $admin = $this->createAdminUser('admin-lazy-load@test.com');

        $todayResponse = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'today']))
            ->assertOk()
            ->assertJsonStructure(['html' => ['today_tab']]);

        $this->assertStringContainsString(
            'Support Intelligence',
            (string) $todayResponse->json('html.today_tab'),
        );
        $this->assertNotSame('', trim((string) $todayResponse->json('html.today_tab')));

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'ira_compact']))
            ->assertOk()
            ->assertJsonStructure(['html' => ['ira_briefing_compact', 'critical_alerts']])
            ->assertSee('View Full Analysis', false);

        $telegramResponse = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'health_telegram']))
            ->assertOk()
            ->assertJsonStructure(['html' => ['team_telegram_status']]);

        $this->assertStringContainsString(
            'Telegram',
            (string) $telegramResponse->json('html.team_telegram_status'),
        );
        $this->assertNotSame('', trim((string) $telegramResponse->json('html.team_telegram_status')));

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'health_cashfree']))
            ->assertOk()
            ->assertJsonStructure(['html' => ['cashfree_health']]);

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'ira_full']))
            ->assertOk()
            ->assertJsonStructure(['html' => ['ira_full_analysis']])
            ->assertSee('IRA Advisor', false)
            ->assertSee('Immediate Risks', false);
    }

    public function test_live_endpoint_returns_expected_lazy_section_keys(): void
    {
        $admin = $this->createAdminUser('admin-lazy-keys@test.com');

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'today,health_telegram']))
            ->assertOk()
            ->assertJsonPath('groups', ['today', 'health_telegram'])
            ->assertJsonStructure([
                'html' => [
                    'today_tab',
                    'team_telegram_status',
                ],
            ]);
    }

    public function test_lazy_live_groups_return_rendered_content_not_loading_placeholders(): void
    {
        $admin = $this->createAdminUser('admin-lazy-content@test.com');

        $lazyGroups = [
            'ira_compact' => ['ira_briefing_compact', 'critical_alerts'],
            'health_radiumbox' => ['radiumbox_health'],
            'health_telegram' => ['team_telegram_status'],
            'today' => ['today_tab'],
        ];

        foreach ($lazyGroups as $group => $sectionKeys) {
            $response = $this->actingAs($admin)
                ->getJson(route('admin.operations.live', ['groups' => $group]))
                ->assertOk();

            foreach ($sectionKeys as $sectionKey) {
                $html = (string) $response->json("html.{$sectionKey}");

                $this->assertNotSame('', trim($html), "Expected {$sectionKey} HTML for group {$group}");
                $this->assertStringNotContainsString('operations-lazy-placeholder', $html);
                $this->assertStringNotContainsString('Loading recommendations', $html);
                $this->assertDoesNotMatchRegularExpression('/Loading .+(?:…|\.\.\.)/', $html);
            }
        }
    }

    public function test_live_refresh_still_returns_core_command_center_sections(): void
    {
        $admin = $this->createAdminUser('admin-live-refresh@test.com');

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'critical,summary,health,ira_compact']))
            ->assertOk()
            ->assertJsonPath('groups', ['critical', 'summary', 'health', 'ira_compact'])
            ->assertJsonStructure([
                'html' => [
                    'critical_alerts',
                    'overview_cards',
                    'health_status',
                    'ira_briefing_compact',
                ],
            ]);
    }

    public function test_sidebar_shows_operations_link_for_admin(): void
    {
        $this->actingAs($this->createAdminUser('admin-ops-nav@test.com'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee(route('admin.operations.index'), false);
    }

    public function test_stale_invalid_cached_dashboard_payload_does_not_crash_dashboard(): void
    {
        $valid = app(OperationsDashboardService::class)->build();

        $invalid = new OperationsDashboardData(
            systemHealth: $valid->systemHealth,
            notificationMetrics: $valid->notificationMetrics,
            automationMetrics: $valid->automationMetrics,
            queueMetrics: $valid->queueMetrics,
            integrationHealth: $valid->integrationHealth,
            radiumBoxHealth: [
                ...$valid->radiumBoxHealth,
                'last_successful_sync_at' => new \stdClass(),
            ],
            cashfreeHealth: $valid->cashfreeHealth,
            recentNotificationFailures: $valid->recentNotificationFailures,
            recentAutomationActivity: $valid->recentAutomationActivity,
            recentIraMessages: $valid->recentIraMessages,
            teamAvailability: $valid->teamAvailability,
            teamTelegramStatus: $valid->teamTelegramStatus,
            cashfreeDeviceEnrichmentQuality: $valid->cashfreeDeviceEnrichmentQuality,
            missingSerialAutomationQuality: $valid->missingSerialAutomationQuality,
            supportIntelligence: $valid->supportIntelligence,
            generatedAt: $valid->generatedAt,
        );

        Cache::put('operations:dashboard:latest:v2', $invalid, now()->addMinute());

        $this->actingAs($this->createAdminUser('admin-ops-stale-cache@test.com'))
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Operations Control Center');
    }

    public function test_operations_dashboard_renders_support_intelligence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->actingAs($this->createAdminUser('admin-support-intelligence@test.com'))
            ->getJson(route('admin.operations.live', ['groups' => 'today']))
            ->assertOk()
            ->assertSee('Support Intelligence')
            ->assertSee('Upcoming Support')
            ->assertSee('Customer Response')
            ->assertSee('Team Workload');
    }

    public function test_support_intelligence_counts_todays_appointments_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Shipra');
        $creator = User::factory()->create();

        $this->createScheduledAppointment($agent, $creator, 'RD-SI-1', '2026-07-06');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-2', '2026-07-06');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-3', '2026-07-07');

        $summary = app(OperationsSupportIntelligenceService::class)->summary();

        $this->assertSame(2, $summary->scheduledToday);
        $this->assertSame(2, $summary->pendingToday);
        $this->assertSame(0, $summary->completedToday);
        $this->assertSame(1, $summary->tomorrow);
    }

    public function test_support_intelligence_counts_waiting_serial_customers_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $creator = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-SERIAL-WAIT-1',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Waiting Customer',
            'status' => 'active',
            'created_by' => $creator->id,
            'missing_serial_first_requested_at' => now()->subHour(),
        ]);

        Order::query()->create([
            'order_id' => 'RD-SERIAL-WAIT-2',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Replied Customer',
            'status' => 'active',
            'created_by' => $creator->id,
            'missing_serial_first_requested_at' => now()->subHours(2),
            'serial_entered_at' => now()->subHour(),
        ]);

        $summary = app(OperationsSupportIntelligenceService::class)->summary();

        $this->assertSame(2, $summary->serialRequested);
        $this->assertSame(1, $summary->serialReceived);
        $this->assertSame(1, $summary->serialStillWaiting);
    }

    public function test_support_intelligence_counts_team_workload_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $shipra = $this->createSupportAgent('Shipra');
        $otherAgent = $this->createSupportAgent('Other Agent');
        $creator = User::factory()->create();

        $this->createScheduledAppointment($shipra, $creator, 'RD-WL-1', '2026-07-06');
        $this->createScheduledAppointment($shipra, $creator, 'RD-WL-2', '2026-07-06');
        $this->createScheduledAppointment($shipra, $creator, 'RD-WL-3', '2026-07-07');
        $this->createScheduledAppointment($otherAgent, $creator, 'RD-WL-4', '2026-07-07');

        $summary = app(OperationsSupportIntelligenceService::class)->summary();
        $shipraWorkload = collect($summary->teamWorkload)->firstWhere('name', 'Shipra');
        $otherWorkload = collect($summary->teamWorkload)->firstWhere('name', 'Other Agent');

        $this->assertNotNull($shipraWorkload);
        $this->assertSame(2, $shipraWorkload['today']);
        $this->assertSame(3, $shipraWorkload['pending']);
        $this->assertNotNull($otherWorkload);
        $this->assertSame(0, $otherWorkload['today']);
        $this->assertSame(1, $otherWorkload['pending']);
    }

    public function test_support_intelligence_does_not_count_completed_old_appointments_as_missed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Shipra');
        $creator = User::factory()->create();

        $this->createScheduledAppointment(
            $agent,
            $creator,
            'RD-SI-COMPLETED-PAST',
            '2026-07-05',
            transactionId: 'TXN-SI-COMPLETED-PAST',
        );

        $summary = app(OperationsSupportIntelligenceService::class)->summary();

        $this->assertSame(0, $summary->missedOverdue);
    }

    public function test_support_intelligence_counts_past_incomplete_appointments_as_missed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Shipra');
        $creator = User::factory()->create();

        $this->createScheduledAppointment($agent, $creator, 'RD-SI-MISSED-1', '2026-07-05');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-MISSED-2', '2026-07-04');

        $summary = app(OperationsSupportIntelligenceService::class)->summary();

        $this->assertSame(2, $summary->missedOverdue);
    }

    public function test_support_intelligence_completed_pending_and_missed_totals_reconcile(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Shipra');
        $creator = User::factory()->create();

        $this->createScheduledAppointment($agent, $creator, 'RD-SI-TODAY-PENDING-1', '2026-07-06');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-TODAY-PENDING-2', '2026-07-06');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-TODAY-DONE-1', '2026-07-06', transactionId: 'TXN-SI-TODAY-1');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-TODAY-DONE-2', '2026-07-06', transactionId: 'TXN-SI-TODAY-2');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-TODAY-DONE-3', '2026-07-06', transactionId: 'TXN-SI-TODAY-3');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-PAST-MISSED-1', '2026-07-05');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-PAST-MISSED-2', '2026-07-04');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-PAST-DONE-1', '2026-07-05', transactionId: 'TXN-SI-PAST-1');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-PAST-DONE-2', '2026-07-04', transactionId: 'TXN-SI-PAST-2');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-PAST-DONE-3', '2026-07-03', transactionId: 'TXN-SI-PAST-3');
        $this->createScheduledAppointment($agent, $creator, 'RD-SI-PAST-DONE-4', '2026-07-02', transactionId: 'TXN-SI-PAST-4');

        $summary = app(OperationsSupportIntelligenceService::class)->summary();

        $this->assertSame(5, $summary->scheduledToday);
        $this->assertSame(2, $summary->pendingToday);
        $this->assertSame(3, $summary->completedToday);
        $this->assertSame(2, $summary->missedOverdue);
        $this->assertSame(
            $summary->scheduledToday,
            $summary->completedToday + $summary->pendingToday,
        );
    }

    private function createSupportAgent(string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

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

    private function createScheduledAppointment(
        User $assignee,
        User $creator,
        string $orderId,
        string $preferredDate,
        ?string $transactionId = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Support Customer',
            'status' => 'active',
            'created_by' => $creator->id,
            'transaction_id' => $transactionId,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Support intelligence case',
            'description' => 'Support intelligence case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
        ]);

        return $incident;
    }
}
