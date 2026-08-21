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
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOwnerIntelligenceService;
use App\Services\Operations\IraOwnerReportFormatter;
use App\Services\RefundNotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperAdminTelegramNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
        ]);

        $this->enableTelegramNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_superadmin_does_not_receive_standalone_sla_risk_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:00:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('800001');
        $results = app(IraCommunicationService::class)->sendRiskAlerts($this->briefingWithSlaDanger());

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'notification_type' => IraNotificationType::RiskAlert->value,
        ]);
    }

    public function test_superadmin_does_not_receive_standalone_open_case_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:00:00', 'Asia/Kolkata'));

        Http::fake();

        $this->createOwnerWithTelegram('800002');
        $results = app(IraCommunicationService::class)->sendRiskAlerts($this->briefingWithHighOpenCases());

        $this->assertSame([], $results);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ira_notifications', [
            'notification_type' => IraNotificationType::UnusualBacklog->value,
        ]);
    }

    public function test_ops_admin_still_receives_low_staffing_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 14:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $opsAdmin = User::factory()->create([
            'name' => 'Ops Admin',
            'telegram_chat_id' => '800003',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $briefing = new IraMorningBriefing(
            greeting: 'Good afternoon.',
            summary: 'Staffing risk.',
            healthStatus: 'warning',
            highlights: [],
            risks: [
                new IraOperationalRisk(
                    key: 'workload.low_staffing',
                    title: 'Low Staffing',
                    category: IraRiskCategory::Workload,
                    severity: AIRiskLevel::High,
                    message: 'No support agents are available.',
                    context: ['available' => 0],
                ),
            ],
            recommendations: [],
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-09',
                operations: [],
                team: ['available' => 0],
                performance: [],
            ),
        );

        $results = app(IraCommunicationService::class)->sendRiskAlerts($briefing);

        $this->assertCount(1, $results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        $this->assertSame($opsAdmin->id, $results[0]->user_id);
        Http::assertSentCount(1);
    }

    public function test_critical_watchdog_alert_still_reaches_superadmin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 23:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 2],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('800004');
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
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        $this->assertSame($owner->id, $results[0]->user_id);
        Http::assertSentCount(1);
    }

    public function test_refund_submission_telegram_skips_superadmin_but_reaches_admin(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 3],
            ], 200),
        ]);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $owner = $this->createOwnerWithTelegram('800005');
        $owner->givePermissionTo('refunds.review');

        $admin = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '800006',
            'telegram_notifications_enabled' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('refunds.review');

        $order = Order::query()->create([
            'order_id' => 'RD-POLICY-REFUND',
            'serial_number' => 'SN-POLICY-REFUND',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000777',
            'amount' => 500,
            'reason' => 'Duplicate payment received from customer.',
            'status' => RefundStatus::Pending,
            'requested_by' => $agent->id,
        ]);

        app(RefundNotificationService::class)->notifyApproversOfSubmission($refund->fresh(['requester']));

        Http::assertSentCount(1);
    }

    public function test_owner_intelligence_summaries_include_refund_aggregates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OWNER-REFUND',
            'serial_number' => 'SN-OWNER-REFUND',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000801',
            'amount' => 500,
            'reason' => 'Pending approval refund request.',
            'status' => RefundStatus::Pending,
            'requested_by' => $agent->id,
            'created_at' => Carbon::parse('2026-07-10 09:30:00'),
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000802',
            'amount' => 750,
            'reason' => 'Pending execution refund request.',
            'status' => RefundStatus::PendingExecution,
            'requested_by' => $agent->id,
            'created_at' => Carbon::parse('2026-07-09 15:00:00'),
        ]);

        $morningReport = app(IraOwnerIntelligenceService::class)->buildMorningReport();
        $morningMessage = implode("\n", app(IraOwnerReportFormatter::class)->formatTelegramMessages($morningReport, 'Ravi'));

        $this->assertStringContainsString('Refunds: 1 pending approval, 1 pending execution', $morningMessage);

        $eveningReport = app(IraOwnerIntelligenceService::class)->buildEveningReport();
        $eveningMessage = implode("\n", app(IraOwnerReportFormatter::class)->formatTelegramMessages($eveningReport, 'Ravi'));

        $this->assertStringContainsString('Refunds: 1 pending approval, 1 pending execution', $eveningMessage);
        $this->assertStringContainsString('submitted today', $eveningMessage);
    }

    private function createOwnerWithTelegram(string $chatId): User
    {
        $owner = User::factory()->create([
            'name' => 'Policy Owner',
            'first_name' => 'Policy',
            'last_name' => 'Owner',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }

    private function briefingWithSlaDanger(): IraMorningBriefing
    {
        return new IraMorningBriefing(
            greeting: 'Good afternoon.',
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
            greeting: 'Good afternoon.',
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
