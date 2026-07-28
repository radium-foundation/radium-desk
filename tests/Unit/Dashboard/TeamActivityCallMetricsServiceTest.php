<?php

namespace Tests\Unit\Dashboard;

use App\Models\BonvoiceCallEvent;
use App\Models\User;
use App\Services\Dashboard\TeamActivityCallMetricsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityCallMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-28 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_team_ivr_calls_total_counts_all_inbound_calls_today(): void
    {
        $this->seedInboundCall('ivr-answered', 'ANSWERED', '08448423017');
        $this->seedInboundCall('ivr-missed', 'NOANSWER', null);
        $this->seedInboundCall('ivr-yesterday', 'ANSWERED', '08448423017', now()->subDay());
        $this->seedOutboundCall('c2c-outbound', 'ANSWERED');

        $service = app(TeamActivityCallMetricsService::class);

        $this->assertSame(2, $service->teamIvrCallsTotalToday());
    }

    public function test_agent_answered_metrics_remain_separate_from_team_ivr_total(): void
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '08448423017',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->seedInboundCall('agent-answered', 'ANSWERED', '08448423017', payload: ['CallDuration' => '300']);
        $this->seedInboundCall('team-missed', 'NOANSWER', null);

        $service = app(TeamActivityCallMetricsService::class);
        $metrics = $service->forUsers([$agent->id]);

        $this->assertSame(2, $service->teamIvrCallsTotalToday());
        $this->assertSame(1, $metrics[$agent->id]->answeredCount);
        $this->assertSame(1, $metrics[$agent->id]->totalCount);
    }

    private function seedInboundCall(
        string $callId,
        string $status,
        ?string $destinationNumber,
        ?Carbon $startedAt = null,
        array $payload = [],
    ): BonvoiceCallEvent {
        return BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'A',
            'customer_phone' => '9876500001',
            'destination_number' => $destinationNumber,
            'direction' => 'Inbound',
            'status' => $status,
            'started_at' => $startedAt ?? now(),
            'payload' => array_merge([
                'callID' => $callId,
                'Status' => $status,
                'Direction' => 'Inbound',
            ], $payload),
        ]);
    }

    private function seedOutboundCall(string $callId, string $status): BonvoiceCallEvent
    {
        return BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'B',
            'customer_phone' => '9876500002',
            'destination_number' => '9846098460',
            'direction' => 'Outbound',
            'status' => $status,
            'started_at' => now(),
            'payload' => [
                'callID' => $callId,
                'Status' => $status,
                'Direction' => 'Outbound',
            ],
        ]);
    }
}
