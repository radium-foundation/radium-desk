<?php

namespace Tests\Unit\Cases;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\ReadModels\Cases\CaseQueueReadModel;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamAvailabilityOverviewService;
use App\Services\Operations\Workforce360Service;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseQueueWorkforceConsumerMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:40:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_scoped_open_counts_match_snapshot_via_for_user(): void
    {
        [$agent, $other] = $this->seedAgentsWithOpenWork();

        $snapshot = DashboardSnapshot::load();
        $readModel = app(CaseQueueReadModel::class);

        $this->assertSame(
            $snapshot->openCount($agent),
            $readModel->forUser($agent)->openCount(),
        );
        $this->assertSame(
            $snapshot->openCount($other),
            $readModel->forUser($other)->openCount(),
        );
        $this->assertSame(2, $readModel->forUser($agent)->openCount());
        $this->assertSame(1, $readModel->forUser($other)->openCount());
        $this->assertSame(
            $snapshot->queueCounts($agent),
            $readModel->forUser($agent)->queueCounts(),
        );
    }

    public function test_team_member_open_counts_match_snapshot_via_for_team_members(): void
    {
        [$agent, $other] = $this->seedAgentsWithOpenWork();

        $snapshot = DashboardSnapshot::load();
        $readModel = app(CaseQueueReadModel::class);
        $expected = [
            $agent->id => $snapshot->openCount($agent),
            $other->id => $snapshot->openCount($other),
        ];

        $this->assertSame(
            $expected,
            $readModel->forTeamMembers([$agent, $other]),
        );
    }

    public function test_global_counts_remain_unchanged_via_global_scope(): void
    {
        $this->seedAgentsWithOpenWork();

        $snapshot = DashboardSnapshot::load();
        $global = app(CaseQueueReadModel::class)->global();

        $this->assertSame($snapshot->openCount(), $global->openCount());
        $this->assertSame($snapshot->waitingCount(), $global->waitingCount());
        $this->assertSame($snapshot->queueCounts(), $global->queueCounts());
        $this->assertSame($snapshot->operationalKpiCounts(), $global->operationalKpiCounts());
    }

    public function test_team_availability_open_work_counts_match_snapshot(): void
    {
        [$agent] = $this->seedAgentsWithOpenWork(startSessions: true);

        $snapshot = DashboardSnapshot::load();
        $expected = $snapshot->openCount($agent);

        $row = app(TeamAvailabilityOverviewService::class)->memberSnapshot($agent);
        $members = collect(app(TeamAvailabilityOverviewService::class)->members());

        $this->assertSame($expected, $row['open_work_count']);
        $this->assertSame(
            $expected,
            $members->firstWhere('id', $agent->id)['open_work_count'] ?? null,
        );
    }

    public function test_workforce360_member_open_work_matches_snapshot(): void
    {
        [$agent] = $this->seedAgentsWithOpenWork(startSessions: true);
        $admin = $this->createAdmin();

        $snapshot = DashboardSnapshot::load();
        $expected = $snapshot->openCount($agent);

        $member = app(Workforce360Service::class)->member($admin, $agent);
        $team = app(Workforce360Service::class)->team($admin);
        $teamRow = collect($team->members)->firstWhere('id', $agent->id);

        $this->assertSame($expected, $member->overview['open_work_count']);
        $this->assertSame($expected, $teamRow['open_work_count'] ?? null);
    }

    public function test_workforce_controller_response_preserves_open_work_counts(): void
    {
        [$agent] = $this->seedAgentsWithOpenWork(startSessions: true);
        $admin = $this->createAdmin();

        $expected = DashboardSnapshot::load()->openCount($agent);

        $index = $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk();

        $show = $this->actingAs($admin)
            ->get(route('workforce.show', $agent))
            ->assertOk();

        $index->assertSee($expected.' Cases', false);
        $this->assertSame(
            $expected,
            app(Workforce360Service::class)->member($admin, $agent)->overview['open_work_count'],
        );
        $show->assertSee((string) $expected, false);
    }

    public function test_scoped_path_does_not_add_sql_vs_snapshot_open_count(): void
    {
        [$agent] = $this->seedAgentsWithOpenWork();
        app(DashboardSnapshotStore::class)->forget();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $snapshot = DashboardSnapshot::load();
        $snapshot->openCount($agent);
        $snapshot->openCount();
        $ownerCount = count(DB::getQueryLog());

        app(DashboardSnapshotStore::class)->forget();
        DB::flushQueryLog();
        $readModel = app(CaseQueueReadModel::class);
        $readModel->forUser($agent)->openCount();
        $readModel->global()->openCount();
        $readModelCount = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($ownerCount, $readModelCount);
    }

    public function test_request_scoped_snapshot_cache_still_used_by_workforce_consumers(): void
    {
        [$agent] = $this->seedAgentsWithOpenWork(startSessions: true);
        app(DashboardSnapshotStore::class)->forget();

        app(TeamAvailabilityOverviewService::class)->memberSnapshot($agent);

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CaseQueueReadModel::class)->forUser($agent)->openCount();
        app(CaseQueueReadModel::class)->global()->openCount();
        $warmQueries = count(DB::getQueryLog());

        $this->assertSame(0, $warmQueries, 'Workforce consumers must reuse DashboardSnapshotStore on subsequent count reads.');
        $this->assertFalse(Cache::has('case-queue-read-model'));
        $this->assertFalse(Cache::has('readmodel:case-queue'));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function seedAgentsWithOpenWork(bool $startSessions = false): array
    {
        $agent = $this->createScheduledAgent('H46D Agent A');
        $other = $this->createScheduledAgent('H46D Agent B');

        $this->createIncident('RD-H46D-A1', $agent, $agent);
        $this->createIncident('RD-H46D-A2', $agent, $agent);
        $this->createIncident('RD-H46D-B1', $other, $other);

        $waiting = $this->createIncident('RD-H46D-WAIT', $agent, $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waiting->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        if ($startSessions) {
            app(PresenceEngineService::class)->startSession($agent->fresh(['workSchedule', 'roles']));
            app(PresenceEngineService::class)->startSession($other->fresh(['workSchedule', 'roles']));
        }

        app(DashboardSnapshotStore::class)->forget();

        return [$agent, $other];
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createScheduledAgent(string $name): User
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

        return $user->fresh(['workSchedule', 'roles']);
    }

    private function createIncident(string $orderId, User $creator, User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'H4-6D Customer',
            'serial_number' => (string) (7_882_000 + (abs(crc32($orderId)) % 9_000)),
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'H4-6D workforce queue consumer test',
            'description' => 'Workforce summary migration seed.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
    }
}
