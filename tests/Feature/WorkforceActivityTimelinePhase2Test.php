<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamMemberActivityService;
use App\Services\Operations\WorkforceActivityContextService;
use App\Services\Operations\WorkforceActivityTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class WorkforceActivityTimelinePhase2Test extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->disableRequestForgeryProtection();

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
            'presence.view_event_cooldown_seconds' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_refresh_produces_no_duplicate_view_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:02:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Refresh Agent');
        $order = $this->createOrder('ORD-REFRESH-1');

        $this->actingAs($agent)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($agent)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($agent)->get(route('orders.show', $order))->assertOk();

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('user_id', $agent->id)
                ->where('event', WorkforceActivityContextService::EVENT_ORDER_VIEWED)
                ->count(),
        );
    }

    public function test_view_cooldown_skips_same_entity_inside_window(): void
    {
        // Mirrors product example with a 60s cooldown:
        // 09:01 View SC12617 → log
        // 09:01:45 View SC12655 → log (different entity)
        // 09:01:50 View SC12617 → skip (same entity still inside cooldown)
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:01:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Cooldown Skip Agent');
        $order = $this->createOrder('ORD-SC-COOL');
        $first = $this->createIncident($agent, $order, 'SC-12617');
        $second = $this->createIncident($agent, $order, 'SC-12655');

        $this->actingAs($agent)->get(route('incidents.show', $first))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:01:45', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('incidents.show', $second))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:01:50', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('incidents.show', $first))->assertOk();

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('user_id', $agent->id)
                ->where('event', WorkforceActivityContextService::EVENT_SERVICE_CASE_VIEWED)
                ->count(),
        );

        $session = WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first();
        $this->assertSame($first->id, $session?->current_incident_id);
    }

    public function test_same_entity_after_cooldown_is_logged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:01:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Cooldown Log Agent');
        $order = $this->createOrder('ORD-AFTER-COOL');
        $incident = $this->createIncident($agent, $order, 'SC-12617');

        $this->actingAs($agent)->get(route('incidents.show', $incident))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:08:00', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('incidents.show', $incident))->assertOk();

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('user_id', $agent->id)
                ->where('event', WorkforceActivityContextService::EVENT_SERVICE_CASE_VIEWED)
                ->count(),
        );
    }

    public function test_different_entities_always_logged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:10:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Different Entity Agent');
        $first = $this->createOrder('ORD-A');
        $second = $this->createOrder('ORD-B');

        $this->actingAs($agent)->get(route('orders.show', $first))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:10:10', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('orders.show', $second))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:11:20', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('orders.show', $first))->assertOk();

        $events = AuditLog::query()
            ->where('user_id', $agent->id)
            ->where('event', WorkforceActivityContextService::EVENT_ORDER_VIEWED)
            ->orderBy('id')
            ->pluck('auditable_id')
            ->all();

        $this->assertSame([$first->id, $second->id, $first->id], $events);
    }

    public function test_order_and_service_case_cooldowns_are_independent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:20:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Independent Cooldown Agent');
        $order = $this->createOrder('ORD-INDEP');
        $incident = $this->createIncident($agent, $order, 'SC-20001');

        $this->actingAs($agent)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($agent)->get(route('incidents.show', $incident))->assertOk();
        $this->actingAs($agent)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($agent)->get(route('incidents.show', $incident))->assertOk();

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('user_id', $agent->id)
                ->where('event', WorkforceActivityContextService::EVENT_ORDER_VIEWED)
                ->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('user_id', $agent->id)
                ->where('event', WorkforceActivityContextService::EVENT_SERVICE_CASE_VIEWED)
                ->count(),
        );
    }

    public function test_current_order_id_updates_on_context_switch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:12:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Order Context Agent');
        $first = $this->createOrder('ORD-CTX-1');
        $second = $this->createOrder('ORD-CTX-2');

        $this->actingAs($agent)->get(route('orders.show', $first))->assertOk();

        $session = WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first();
        $this->assertNotNull($session);
        $this->assertSame($first->id, $session->current_order_id);
        $this->assertSame(WorkforceActivityContextService::EVENT_ORDER_VIEWED, $session->last_business_action);
        $this->assertNotNull($session->last_business_action_at);
        $this->assertNotNull($session->last_order_viewed_at);

        $this->actingAs($agent)->get(route('orders.show', $second))->assertOk();
        $session->refresh();

        $this->assertSame($second->id, $session->current_order_id);
    }

    public function test_last_business_action_at_updates_on_real_business_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:30:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Business Action Agent');
        $session = WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first();
        $this->assertNotNull($session);
        $this->assertNull($session->last_business_action_at);

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:31:00', 'Asia/Kolkata'));
        app(TeamMemberActivityService::class)->recordCustomerCommunication($agent);

        $session->refresh();
        $this->assertSame('09:31', $session->last_business_action_at?->format('H:i'));
        $this->assertSame('communication.sent', $session->last_business_action);

        $order = $this->createOrder('ORD-BIZ-1');
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:32:00', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('orders.show', $order))->assertOk();

        $session->refresh();
        $this->assertSame('09:32', $session->last_business_action_at?->format('H:i'));
        $this->assertSame(WorkforceActivityContextService::EVENT_ORDER_VIEWED, $session->last_business_action);
    }

    public function test_heartbeat_does_not_modify_last_business_action_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:40:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Heartbeat Business Agent');
        $session = WorkSession::query()->where('user_id', $agent->id)->whereNull('logout_at')->first();
        $this->assertNotNull($session);

        $businessAt = Carbon::parse('2026-07-18 09:39:00', 'Asia/Kolkata');
        $session->update([
            'last_activity_at' => $businessAt,
            'last_tick_at' => $businessAt,
            'last_business_action' => 'communication.sent',
            'last_business_action_at' => $businessAt,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:41:00', 'Asia/Kolkata'));

        $this->actingAs($agent)
            ->postJson(route('presence.heartbeat'))
            ->assertOk();

        $session->refresh();

        $this->assertSame('09:41', $session->last_activity_at?->format('H:i'));
        $this->assertSame('09:39', $session->last_business_action_at?->format('H:i'));
        $this->assertSame('communication.sent', $session->last_business_action);
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('event', 'like', '%heartbeat%')
                ->count(),
        );
    }

    public function test_ajax_and_livewire_requests_do_not_log_view_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:25:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Ajax Guard Agent');
        $order = $this->createOrder('ORD-AJAX-1');

        $this->actingAs($agent)
            ->getJson(route('orders.show', $order))
            ->assertOk();

        $this->actingAs($agent)
            ->withHeaders(['X-Livewire' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('orders.show', $order))
            ->assertOk();

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('user_id', $agent->id)
                ->where('event', WorkforceActivityContextService::EVENT_ORDER_VIEWED)
                ->count(),
        );
    }

    public function test_timeline_projects_login_views_and_logout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSession('Timeline Projection Agent');
        $order = $this->createOrder('ORD-TL-1');
        $first = $this->createIncident($agent, $order, 'SC-12617');
        $second = $this->createIncident($agent, $order, 'SC-12655');

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:02:00', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('incidents.show', $first))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:10:00', 'Asia/Kolkata'));
        $this->actingAs($agent)->get(route('incidents.show', $second))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-18 09:50:00', 'Asia/Kolkata'));
        app(PresenceEngineService::class)->closeSession(
            $agent,
            WorkSessionEndReason::ManualLogout,
        );

        $timeline = app(WorkforceActivityTimelineService::class)->forUserOnDate(
            $agent,
            Carbon::parse('2026-07-18', 'Asia/Kolkata'),
        );

        $simplified = array_map(
            static fn (array $entry): array => [
                'time' => $entry['time'],
                'label' => $entry['label'],
            ],
            $timeline,
        );

        $this->assertSame(
            [
                ['time' => '09:00', 'label' => 'Login'],
                ['time' => '09:02', 'label' => 'Viewed SC12617'],
                ['time' => '09:10', 'label' => 'Viewed SC12655'],
                ['time' => '09:50', 'label' => 'Logout'],
            ],
            $simplified,
        );
    }

    private function createAgentWithSession(string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'password' => bcrypt('password'),
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
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

        app(PresenceEngineService::class)->startSession($user->fresh(['workSchedule']));

        return $user->fresh(['workSchedule']);
    }

    private function createOrder(string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Timeline Customer',
            'customer_email' => 'timeline@example.com',
            'customer_phone' => '9999999999',
        ]);
    }

    private function createIncident(User $creator, Order $order, string $referenceNo): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Timeline case '.$referenceNo,
            'description' => 'Service case for workforce timeline tests.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
