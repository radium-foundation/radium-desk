<?php

namespace Tests\Feature;

use App\Enums\TeamAvailabilityStatus;
use App\Models\BonvoiceCallEvent;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\Operations\PresenceEngineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityCallsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-28 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_calls_column_shows_agent_answered_with_team_ivr_total_superscript(): void
    {
        $viewer = $this->supervisor();
        $agent = $this->createAgent('Calls Agent', bonvoiceExtension: '08448423017');

        $this->seedInboundCall('agent-answered-1', 'ANSWERED', '08448423017', ['CallDuration' => '150']);
        $this->seedInboundCall('agent-answered-2', 'ANSWERED', '08448423017', ['CallDuration' => '150']);
        $this->seedInboundCall('team-missed-1', 'NOANSWER', null);
        $this->seedInboundCall('team-missed-2', 'NOINPUT', null);

        $panel = app(TeamActivityPanelService::class)->build();
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row->callsAnsweredToday);
        $this->assertSame(4, $panel->ivrCallsTotalToday);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-calls-compact__count">2<', $html);
        $this->assertStringContainsString('team-activity-calls-compact__sup', $html);
        $this->assertStringContainsString('title="Total IVR calls received today (team-wide)"', $html);
        $this->assertStringContainsString('>4</sup>', $html);
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

    private function createAgent(string $name, ?string $bonvoiceExtension = null, bool $startSession = true): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'bonvoice_extension' => $bonvoiceExtension,
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function seedInboundCall(
        string $callId,
        string $status,
        ?string $destinationNumber,
        array $payload = [],
    ): BonvoiceCallEvent {
        return BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'A',
            'customer_phone' => '9876500001',
            'destination_number' => $destinationNumber,
            'direction' => 'Inbound',
            'status' => $status,
            'started_at' => now(),
            'payload' => array_merge([
                'callID' => $callId,
                'Status' => $status,
                'Direction' => 'Inbound',
            ], $payload),
        ]);
    }
}
