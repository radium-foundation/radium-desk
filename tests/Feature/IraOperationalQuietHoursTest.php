<?php

namespace Tests\Feature;

use App\Data\Operations\IraCommunicationInput;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IraRiskCategory;
use App\Models\IraNotification;
use App\Models\User;
use App\Services\Operations\IraCommunicationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IraOperationalQuietHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
            'ira.communication.quiet_hours.enabled' => true,
            'ira.communication.quiet_hours.start' => '21:00',
            'ira.communication.quiet_hours.end' => '08:00',
            'app.schedule_timezone' => 'Asia/Kolkata',
        ]);

        $this->enableTelegramNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_high_priority_sla_risk_remains_suppressed_outside_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:59:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('100200300');
        $service = app(IraCommunicationService::class);

        $results = $service->sendRiskAlerts($this->briefingWithSlaDanger());

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }

    public function test_high_priority_sla_risk_suppressed_at_quiet_hours_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 21:00:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('100200301');
        $service = app(IraCommunicationService::class);

        $results = $service->sendRiskAlerts($this->briefingWithSlaDanger());

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'notification_type' => IraNotificationType::RiskAlert->value,
        ]);
    }

    public function test_high_open_cases_suppressed_at_quiet_hours_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 21:00:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('100200302');
        $service = app(IraCommunicationService::class);

        $results = $service->sendRiskAlerts($this->briefingWithHighOpenCases());

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'notification_type' => IraNotificationType::UnusualBacklog->value,
        ]);
    }

    public function test_routine_risk_remains_suppressed_before_quiet_hours_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 07:59:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('100200303');
        $service = app(IraCommunicationService::class);

        $results = $service->sendRiskAlerts($this->briefingWithSlaDanger());

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }

    public function test_routine_risk_remains_suppressed_after_quiet_hours_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('100200304');
        $service = app(IraCommunicationService::class);

        $results = $service->sendRiskAlerts($this->briefingWithSlaDanger());

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }

    public function test_integration_failure_suppressed_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('100200305');
        $service = app(IraCommunicationService::class);

        $results = $service->sendRiskAlerts($this->emptyBriefing());

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'notification_type' => IraNotificationType::IntegrationFailure->value,
        ]);
    }

    public function test_critical_watchdog_alert_deliverable_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 23:30:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 3],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('100200306');
        $service = app(IraCommunicationService::class);

        $results = $service->dispatch(new IraCommunicationInput(
            event: IraNotificationType::CriticalSystemAlert,
            context: [
                'label' => 'Queue',
                'message' => '3 failed job(s) in dead-letter queue.',
                'affected_count' => 3,
                'dedupe_key' => 'watchdog:queue:dead_letter',
            ],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        $this->assertSame($owner->id, $results[0]->user_id);
        Http::assertSentCount(1);
    }

    public function test_daytime_cooldown_still_applies_outside_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 4],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('100200307');
        $service = app(IraCommunicationService::class);
        $input = new IraCommunicationInput(
            event: IraNotificationType::IntegrationFailure,
            context: [
                'label' => 'Email',
                'message' => 'SMTP unavailable.',
                'dedupe_key' => 'integration:email',
            ],
        );

        $first = $service->dispatch($input);
        $second = $service->dispatch($input);

        $this->assertCount(1, $first);
        $this->assertSame(IraNotificationStatus::Sent, $first[0]->status);
        $this->assertSame([], $second);
        $this->assertSame(1, IraNotification::query()->where('user_id', $owner->id)->count());
        Http::assertSentCount(1);
    }

    private function createOwnerWithTelegram(string $chatId): User
    {
        $owner = User::factory()->create([
            'name' => 'Quiet Hours Owner',
            'first_name' => 'Quiet',
            'last_name' => 'Owner',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }

    private function emptyBriefing(): IraMorningBriefing
    {
        return new IraMorningBriefing(
            greeting: 'Good evening.',
            summary: 'Operations summary.',
            healthStatus: 'warning',
            highlights: [],
            risks: [],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: [],
                team: [],
                performance: [],
            ),
        );
    }

    private function briefingWithSlaDanger(): IraMorningBriefing
    {
        return new IraMorningBriefing(
            greeting: 'Good evening.',
            summary: 'SLA attention needed.',
            healthStatus: 'critical',
            highlights: [],
            risks: [
                new IraOperationalRisk(
                    key: 'customer.sla_danger',
                    title: 'SLA Breach Risk',
                    category: IraRiskCategory::Customer,
                    severity: AIRiskLevel::High,
                    message: '3 cases risk SLA breach.',
                    context: ['overdue' => 2, 'warning' => 1],
                ),
            ],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: ['overdue' => 2, 'warning' => 1],
                team: [],
                performance: [],
            ),
        );
    }

    private function briefingWithHighOpenCases(): IraMorningBriefing
    {
        return new IraMorningBriefing(
            greeting: 'Good evening.',
            summary: 'Backlog attention needed.',
            healthStatus: 'warning',
            highlights: [],
            risks: [
                new IraOperationalRisk(
                    key: 'workload.high_open_cases',
                    title: 'Unusual Backlog',
                    category: IraRiskCategory::Workload,
                    severity: AIRiskLevel::High,
                    message: 'Open case volume is unusually high.',
                    context: ['open_cases' => 45, 'threshold' => 30],
                ),
            ],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: ['open_cases' => 45],
                team: [],
                performance: [],
            ),
        );
    }
}
