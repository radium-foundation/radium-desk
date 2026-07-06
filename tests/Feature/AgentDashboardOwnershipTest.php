<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AgentDashboardOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_assigned_attention_case_appears_in_agent_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('Attention Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $attentionCase = $this->createIncident('RD-ATTENTION-1', $creator, $agent);
        $attentionCase->forceFill([
            'created_at' => now()->subHours(72),
            'updated_at' => now()->subHours(72),
        ])->save();

        $otherAgentCase = $this->createIncident('RD-ATTENTION-2', $creator, User::factory()->create());

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $attentionCase->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame('attention', $classifier->classify($incident)->value);
        $this->assertTrue($classifier->matchesMyWork($incident, $agent));

        $myWork = DashboardSnapshot::load()->incidentsForQueue('my_work', $agent);

        $this->assertTrue($myWork->contains(fn (Incident $case): bool => $case->id === $attentionCase->id));
        $this->assertFalse($myWork->contains(fn (Incident $case): bool => $case->id === $otherAgentCase->id));

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($attentionCase->reference_no)
            ->assertDontSee($otherAgentCase->reference_no);

        Carbon::setTestNow();
    }

    public function test_rd3442035_validation_failed_case_shows_badge_and_verify_action_on_agent_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('Abhinav');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3442035',
            'serial_number' => '54SAXXC5514586',
            'device_model' => 'MFS110',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'created_by' => $creator->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial validation failed',
            'description' => 'Production validation failure example.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $freshIncident = $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame('attention', $classifier->classify($freshIncident)->value);
        $this->assertTrue($classifier->matchesMyWork($freshIncident, $agent));

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('RD3442035')
            ->assertSee('Serial validation failed', false)
            ->assertSee('Verify serial/device', false);

        Carbon::setTestNow();
    }

    public function test_agent_dashboard_hides_global_metrics_and_shows_personal_kpis(): void
    {
        $agent = $this->createAgent('KPI Agent');

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My Active Work')
            ->assertSee('My Attention')
            ->assertSee('My Scheduled Today')
            ->assertSee('My Waiting Follow-ups')
            ->assertSee('My Completed Today')
            ->assertDontSee('>Total Active Cases<', false)
            ->assertDontSee('>Customer Waiting<', false)
            ->assertDontSee('>Pending Admin<', false)
            ->assertDontSee('>Open<', false)
            ->assertDontSee('>Overdue<', false);
    }

    public function test_admin_dashboard_keeps_global_operation_metrics(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Action Required')
            ->assertSee('Attention')
            ->assertSee('Customer Waiting')
            ->assertSee('>Open<', false)
            ->assertSee('>Overdue<', false)
            ->assertDontSee('My Active Work')
            ->assertDontSee('My Attention');
    }

    public function test_assigned_waiting_customer_without_follow_up_is_excluded_from_my_work(): void
    {
        $agent = $this->createAgent('Waiting Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $waitingCase = $this->createIncident('RD-WAIT-NO-FU', $creator, $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $waitingCase->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);

        $this->assertTrue($classifier->isWaitingCustomer($incident));
        $this->assertFalse($classifier->matchesMyWork($incident, $agent));
        $this->assertSame(0, DashboardSnapshot::load()->incidentsForQueue('my_work', $agent)->count());
    }

    public function test_assigned_waiting_customer_with_due_follow_up_is_included_in_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 14:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('Follow-up Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $waitingCase = $this->createIncident('RD-WAIT-FU', $creator, $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::Photos,
            'started_at' => now()->subDay(),
            'next_action_at' => now()->subHour(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $waitingCase->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);

        $this->assertTrue($classifier->isWaitingFollowUpDue($incident));
        $this->assertTrue($classifier->matchesMyWork($incident, $agent));
        $this->assertSame(1, DashboardSnapshot::load()->incidentsForQueue('my_work', $agent)->count());

        Carbon::setTestNow();
    }

    public function test_my_work_quick_search_filters_by_order_id(): void
    {
        $agent = $this->createAgent('Search Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $match = $this->createIncident('RD-SEARCH-ME', $creator, $agent);
        $this->createIncident('RD-OTHER', $creator, $agent);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.load-more', [
                'queue' => DashboardPersonalizationService::QUEUE_MY_WORK,
                'q' => 'RD-SEARCH-ME',
            ]))
            ->assertOk()
            ->assertJsonPath('total_count', 1)
            ->assertJsonCount(1, 'rows')
            ->assertSee('RD-SEARCH-ME', false);
    }

    private function createAgent(string $name): User
    {
        $agent = User::factory()->create(['name' => $name]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function createIncident(string $orderId, User $creator, ?User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Agent dashboard ownership test',
            'description' => 'Agent dashboard ownership test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }
}
