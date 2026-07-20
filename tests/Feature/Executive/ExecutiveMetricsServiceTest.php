<?php

namespace Tests\Feature\Executive;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\PlatformHealthStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Executive\ExecutiveMetricsService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExecutiveMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:40:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createAgent(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createIncident(User $actor, IncidentStatus $status, bool $highPriority = false): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-METRIC-'.uniqid(),
            'customer_name' => 'Metric Customer',
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
            'title' => 'Metric test case',
            'description' => 'Metric test case.',
            'status' => $status,
            'high_priority' => $highPriority,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }

    public function test_snapshot_includes_all_eight_metrics_with_expected_counts(): void
    {
        $actor = $this->createAgent();
        $this->createIncident($actor, IncidentStatus::Open);
        $this->createIncident($actor, IncidentStatus::Open, highPriority: true);
        $critical = $this->createIncident($actor, IncidentStatus::InProgress, highPriority: true);

        RefundRequest::query()->create([
            'order_id' => $critical->order_id,
            'incident_id' => $critical->id,
            'reference_no' => 'REF-METRIC-0001',
            'amount' => 1000,
            'reason' => 'Metric refund queue test',
            'status' => RefundStatus::Pending,
            'requested_by' => $actor->id,
        ]);

        $snapshot = app(ExecutiveMetricsService::class)->snapshot();

        $this->assertCount(8, $snapshot->metrics);
        $this->assertSame(3, $snapshot->get('open_cases')->value);
        $this->assertSame(2, $snapshot->get('critical_cases')->value);
        $this->assertSame(PlatformHealthStatus::Warning, $snapshot->get('critical_cases')->status);
        $this->assertSame(1, $snapshot->get('refund_queue')->value);
        $this->assertNotNull($snapshot->get('open_cases')->detailUrl);
        $this->assertNotNull($snapshot->get('active_agents')->detailUrl);
    }

    public function test_snapshot_uses_laravel_cache_across_service_instances(): void
    {
        $actor = $this->createAgent();
        $this->createIncident($actor, IncidentStatus::Open);

        $first = app(ExecutiveMetricsService::class)->snapshot();
        $this->assertSame(1, $first->get('open_cases')->value);

        $this->createIncident($actor, IncidentStatus::Open);

        app()->forgetInstance(ExecutiveMetricsService::class);
        $cached = app(ExecutiveMetricsService::class)->snapshot();
        $this->assertSame(1, $cached->get('open_cases')->value);

        $refreshed = app(ExecutiveMetricsService::class)->refresh();
        $this->assertSame(2, $refreshed->get('open_cases')->value);
    }

    public function test_force_refresh_bypasses_cache(): void
    {
        $actor = $this->createAgent();
        $this->createIncident($actor, IncidentStatus::Open);

        $service = app(ExecutiveMetricsService::class);
        $this->assertSame(1, $service->snapshot()->get('open_cases')->value);

        $this->createIncident($actor, IncidentStatus::Open);

        $this->assertSame(1, $service->get('open_cases')->value);
        $this->assertSame(2, $service->get('open_cases', force: true)->value);
    }
}
