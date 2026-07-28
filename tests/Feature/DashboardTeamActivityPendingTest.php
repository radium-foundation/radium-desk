<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\ReadModels\Cases\CaseQueueReadModel;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityPendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-28 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_panel_renders_pending_column_with_open_and_overdue_counts(): void
    {
        $viewer = $this->supervisor();
        $agent = $this->createAgent('Workload Agent', startSession: true);

        $this->createAssignedIncident($agent, 'RD-PENDING-1');
        $this->createAssignedIncident($agent, 'RD-PENDING-2');
        $this->createOverdueIncident($agent, 'RD-OVERDUE-1', Carbon::parse('2026-07-25 10:00:00', 'Asia/Kolkata'));

        app(DashboardSnapshotStore::class)->forget();

        $row = collect(app(TeamActivityPanelService::class)->build()->agents)
            ->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertSame(3, $row->pendingCasesCount);
        $this->assertSame(1, $row->overdueCasesCount);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('>Pending<', $html);
        $this->assertStringContainsString('team-activity-col--pending', $html);
        $this->assertStringContainsString('team-activity-pending-compact', $html);
        $this->assertStringContainsString('team-activity-calls-compact__count">3<', $html);
        $this->assertStringContainsString('team-activity-calls-compact__sup">1<', $html);
        $this->assertStringContainsString('title="Pending Cases', $html);
        $this->assertStringContainsString('Overdue: 1', $html);
    }

    public function test_pending_column_hides_overdue_superscript_when_zero(): void
    {
        $viewer = $this->supervisor();
        $agent = $this->createAgent('Clean Agent', startSession: true);
        $this->createAssignedIncident($agent, 'RD-PENDING-ONLY');

        app(DashboardSnapshotStore::class)->forget();

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-calls-compact__count">1<', $html);
        $this->assertStringNotContainsString('team-activity-pending-compact__sup', $html);
        $this->assertStringContainsString('Overdue: 0', $html);
    }

    public function test_workload_counts_match_case_queue_read_model(): void
    {
        $agent = $this->createAgent('Parity Agent');
        $this->createAssignedIncident($agent, 'RD-PARITY-1');
        $this->createAssignedIncident($agent, 'RD-PARITY-2');
        $this->createOverdueIncident($agent, 'RD-PARITY-O', Carbon::parse('2026-07-25 10:00:00', 'Asia/Kolkata'));

        app(DashboardSnapshotStore::class)->forget();

        $snapshot = DashboardSnapshot::load();
        $expected = app(CaseQueueReadModel::class)->workloadForTeamMembers([$agent], snapshot: $snapshot)[$agent->id];

        $row = collect(app(TeamActivityPanelService::class)->build()->agents)
            ->firstWhere('id', $agent->id);

        $this->assertSame($expected['pending'], $row->pendingCasesCount);
        $this->assertSame($expected['overdue'], $row->overdueCasesCount);
    }

    private function panelHtml(User $viewer): string
    {
        return (string) $this->actingAs($viewer)
            ->getJson(route('dashboard.team-activity'))
            ->assertOk()
            ->json('html');
    }

    private function supervisor(): User
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $viewer;
    }

    private function createAgent(string $name, bool $startSession = false): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
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

        if ($startSession) {
            app(PresenceEngineService::class)->startSession($user->fresh(['workSchedule', 'roles']));
        }

        return $user->fresh(['workSchedule', 'roles']);
    }

    private function createAssignedIncident(User $assignee, string $orderId): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Pending Test Customer',
            'serial_number' => (string) (8_000_000 + abs(crc32($orderId)) % 9_000),
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $assignee->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Pending workload test case',
            'description' => 'Pending workload test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $assignee->id,
            'updated_by' => $assignee->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
    }

    private function createOverdueIncident(User $assignee, string $orderId, Carbon $createdAt): Incident
    {
        $incident = $this->createAssignedIncident($assignee, $orderId);
        $incident->created_at = $createdAt;
        $incident->saveQuietly();

        $this->assertSame(ServiceCaseSlaStatus::Overdue, $incident->fresh()->slaStatus());

        return $incident->fresh();
    }
}
