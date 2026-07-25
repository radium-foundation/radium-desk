<?php

namespace Tests\Unit\Operations;

use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\TeamAvailabilityOverviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamAvailabilityOverviewOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_overview_reuses_single_team_member_query_and_batched_sessions(): void
    {
        $agents = User::factory()->count(3)->create(['is_active' => true]);

        foreach ($agents as $index => $agent) {
            $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
            $agent->update(['availability_status' => TeamAvailabilityStatus::Available]);

            WorkSession::query()->create([
                'user_id' => $agent->id,
                'work_date' => now()->toDateString(),
                'login_at' => now()->subHours(2),
                'logout_at' => now()->subHour(),
                'ended_reason' => 'manual_logout',
            ]);
        }

        $service = app(TeamAvailabilityOverviewService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $overview = $service->overview();
        $again = $service->overview();
        $tracked = $service->trackedMembers();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($overview, $again);
        $this->assertCount(3, $tracked);

        $userListQueries = collect($queries)
            ->filter(fn (array $query): bool => str_contains(strtolower($query['sql'] ?? $query['query']), ' from "users"')
                || str_contains(strtolower($query['sql'] ?? $query['query']), ' from `users`'))
            ->count();

        $batchedSessionSummaryQueries = collect($queries)
            ->filter(function (array $query): bool {
                $sql = strtolower($query['sql'] ?? $query['query']);

                return str_contains($sql, 'work_sessions')
                    && str_contains($sql, 'user_id')
                    && (str_contains($sql, ' in (') || str_contains($sql, ' in('));
            })
            ->count();

        $this->assertLessThanOrEqual(2, $userListQueries, 'Team member list should be loaded at most once per request.');
        $this->assertSame(1, $batchedSessionSummaryQueries, 'Today session summaries should be batched into one whereIn query.');
        $this->assertIsArray($overview['on_duty']);
        $this->assertIsArray($overview['unavailable']);
    }

    public function test_members_and_unavailable_delegate_to_shared_overview(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $service = app(TeamAvailabilityOverviewService::class);
        $overview = $service->overview();

        $this->assertSame($overview['on_duty'], $service->members());
        $this->assertSame($overview['unavailable'], $service->unavailableMembers());
    }
}
