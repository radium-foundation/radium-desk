<?php

namespace Tests\Feature\Platform;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\PlatformHealthStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Platform\PlatformDashboardService;
use App\Services\Platform\PlatformHealthCache;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExecutiveCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:40:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create([
            'email' => 'exec-superadmin@test.com',
            'is_active' => true,
            'password' => bcrypt('password'),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function createAgent(): User
    {
        $user = User::factory()->create([
            'email' => 'exec-agent@test.com',
            'is_active' => true,
            'password' => bcrypt('password'),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createIncident(User $actor, IncidentStatus $status, bool $highPriority = false): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-'.uniqid(),
            'customer_name' => 'Exec Customer',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Executive test case',
            'description' => 'Executive test case.',
            'status' => $status,
            'high_priority' => $highPriority,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }

    public function test_command_center_renders_executive_snapshot_and_placeholders(): void
    {
        $actor = $this->createAgent();
        $this->createIncident($actor, IncidentStatus::Open, highPriority: true);

        $response = $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'));

        $response->assertOk()
            ->assertSee('Command Center', false)
            ->assertSee('Executive Snapshot', false)
            ->assertSee('Open Cases', false)
            ->assertSee('Critical Cases', false)
            ->assertSee('Refund Queue', false)
            ->assertSee('Active Agents', false)
            ->assertSee('Customers Waiting', false)
            ->assertSee('Orders Today', false)
            ->assertSee('Resolved Today', false)
            ->assertSee('Appointments Today', false)
            ->assertSee('Platform Health', false)
            ->assertSee('Business Operations', false)
            ->assertSee('Customer Operations', false)
            ->assertSee('Cards coming next', false);
    }

    public function test_executive_kpi_counts_reflect_data(): void
    {
        $actor = $this->createAgent();
        $this->createIncident($actor, IncidentStatus::Open);
        $this->createIncident($actor, IncidentStatus::Open);
        $critical = $this->createIncident($actor, IncidentStatus::InProgress, highPriority: true);

        RefundRequest::query()->create([
            'order_id' => $critical->order_id,
            'incident_id' => $critical->id,
            'reference_no' => 'REF-EXEC-0001',
            'amount' => 1000,
            'reason' => 'Executive refund queue test',
            'status' => RefundStatus::Pending,
            'requested_by' => $actor->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD-EXEC-TODAY',
            'customer_name' => 'Today Customer',
            'serial_number' => 'FPSPL1141YY',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);

        $dashboard = app(PlatformDashboardService::class)->build($this->createSuperadmin());
        $executive = collect($dashboard->sections)->firstWhere('key', 'executive');
        $this->assertNotNull($executive);

        $cards = collect($executive['cards'])->keyBy(fn ($card) => $card->key);

        $this->assertSame(3, $cards['exec_open_cases']->meta['value'] ?? null);
        $this->assertSame(1, $cards['exec_critical_cases']->meta['value'] ?? null);
        $this->assertSame(PlatformHealthStatus::Warning, $cards['exec_critical_cases']->status);
        $this->assertSame(1, $cards['exec_refund_queue']->meta['value'] ?? null);
        $this->assertGreaterThanOrEqual(1, $cards['exec_orders_today']->meta['value'] ?? 0);
    }

    public function test_superadmin_login_lands_on_command_center(): void
    {
        $user = $this->createSuperadmin();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.platform.index', absolute: false));
    }

    public function test_agent_login_lands_on_dashboard(): void
    {
        $user = $this->createAgent();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_executive_metric_card_refresh_returns_html(): void
    {
        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.cards.show', ['card' => 'exec_open_cases']));

        $response->assertOk()
            ->assertJsonPath('key', 'exec_open_cases')
            ->assertJsonStructure(['html', 'payload', 'generated_at']);

        $this->assertStringContainsString('platform-executive-metric', (string) $response->json('html'));
    }

    public function test_executive_card_refresh_bypasses_metrics_cache(): void
    {
        $actor = $this->createAgent();
        $this->createIncident($actor, IncidentStatus::Open);

        $superadmin = $this->createSuperadmin();
        $dashboard = app(PlatformDashboardService::class)->build($superadmin);
        $executive = collect($dashboard->sections)->firstWhere('key', 'executive');
        $cards = collect($executive['cards'])->keyBy(fn ($card) => $card->key);
        $this->assertSame(1, $cards['exec_open_cases']->meta['value'] ?? null);

        $this->createIncident($actor, IncidentStatus::Open);

        $response = $this->actingAs($superadmin)
            ->getJson(route('admin.platform.cards.show', ['card' => 'exec_open_cases']));

        $response->assertOk();
        $this->assertSame(2, data_get($response->json('payload'), 'meta.value'));
    }
}
