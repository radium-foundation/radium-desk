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
        $this->assertSame(0, $metrics[$agent->id]->agentDisconnectedCount);
    }

    public function test_agent_disconnected_count_only_includes_terminal_hangup_by_agent(): void
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '08448423017',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->seedInboundCall('agent-dc', 'ANSWERED', '08448423017', payload: [
            'callType' => '2',
            'HangupBy' => 'agent',
            'CallDuration' => '30',
        ]);
        $this->seedInboundCall('customer-dc', 'ANSWERED', '08448423017', payload: [
            'callType' => '2',
            'HangupBy' => 'customer',
        ]);
        $this->seedInboundCall('system-dc', 'NOANSWER', '08448423017', payload: [
            'callType' => '2',
            'HangupBy' => 'system',
        ]);
        $this->seedInboundCall('missing-dc', 'NOANSWER', '08448423017', payload: [
            'callType' => '2',
        ]);
        $this->seedInboundCall('ringing', 'RINGING', '08448423017', payload: [
            'callType' => '0.5',
            'HangupBy' => 'agent',
        ]);

        $metrics = app(TeamActivityCallMetricsService::class)->forUsers([$agent->id])[$agent->id];

        $this->assertSame(5, $metrics->totalCount);
        $this->assertSame(1, $metrics->agentDisconnectedCount);
    }

    public function test_agent_disconnected_count_uses_canonical_call_event_not_duplicate_webhooks(): void
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '08448423017',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'canonical-agent-dc',
            'leg' => 'call',
            'customer_phone' => '9876500001',
            'destination_number' => '08448423017',
            'direction' => 'Inbound',
            'status' => 'ANSWERED',
            'call_type' => '2',
            'started_at' => now(),
            'payload' => [
                'callID' => 'canonical-agent-dc',
                'Status' => 'ANSWERED',
                'Direction' => 'Inbound',
                'callType' => '2',
                'HangupBy' => 'agent',
            ],
        ]);

        $metrics = app(TeamActivityCallMetricsService::class)->forUsers([$agent->id])[$agent->id];

        $this->assertSame(1, $metrics->totalCount);
        $this->assertSame(1, $metrics->agentDisconnectedCount);
    }

    public function test_multiple_agents_receive_independent_agent_disconnected_counts(): void
    {
        $agentA = User::factory()->create(['bonvoice_extension' => '08448423017']);
        $agentA->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agentB = User::factory()->create(['bonvoice_extension' => '08448423018']);
        $agentB->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->seedInboundCall('agent-a-dc', 'ANSWERED', '08448423017', payload: [
            'callType' => '2',
            'HangupBy' => 'agent',
        ]);
        $this->seedInboundCall('agent-a-customer', 'ANSWERED', '08448423017', payload: [
            'callType' => '2',
            'HangupBy' => 'customer',
        ]);
        $this->seedInboundCall('agent-b-dc-1', 'ANSWERED', '08448423018', payload: [
            'callType' => '2',
            'HangupBy' => 'agent',
        ]);
        $this->seedInboundCall('agent-b-dc-2', 'ANSWERED', '08448423018', payload: [
            'callType' => '2',
            'HangupBy' => 'agent',
        ]);

        $metrics = app(TeamActivityCallMetricsService::class)->forUsers([$agentA->id, $agentB->id]);

        $this->assertSame(1, $metrics[$agentA->id]->agentDisconnectedCount);
        $this->assertSame(2, $metrics[$agentB->id]->agentDisconnectedCount);
        $this->assertSame(2, $metrics[$agentA->id]->totalCount);
        $this->assertSame(2, $metrics[$agentB->id]->totalCount);
    }

    private function seedInboundCall(
        string $callId,
        string $status,
        ?string $destinationNumber,
        ?Carbon $startedAt = null,
        array $payload = [],
    ): BonvoiceCallEvent {
        $callType = $payload['callType'] ?? null;

        return BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'A',
            'customer_phone' => '9876500001',
            'destination_number' => $destinationNumber,
            'direction' => 'Inbound',
            'status' => $status,
            'call_type' => is_string($callType) ? $callType : null,
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
