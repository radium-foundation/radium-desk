<?php

namespace Tests\Unit\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\AutomationHealthService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationHealthServiceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_dashboard_aggregation_cache_miss_populates_cache_key(): void
    {
        Cache::flush();
        $this->seedSuccessfulWaitingExecution();

        $cacheKey = AutomationHealthService::aggregationCacheKey();

        $this->assertFalse(Cache::has($cacheKey));

        app(AutomationHealthService::class)->dashboardData();

        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_dashboard_aggregation_cache_hit_returns_same_kpis(): void
    {
        Cache::flush();
        $this->seedSuccessfulWaitingExecution();

        $service = app(AutomationHealthService::class);
        $first = $service->dashboardData();
        $second = $service->dashboardData();

        $this->assertSame(
            $this->normalizeAggregationForAssertion($first),
            $this->normalizeAggregationForAssertion($second),
        );
    }

    public function test_dashboard_aggregation_cache_hit_reduces_database_queries(): void
    {
        Cache::flush();
        $this->seedSuccessfulWaitingExecution();

        $service = app(AutomationHealthService::class);

        DB::enableQueryLog();
        $service->dashboardData();
        $missQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $service->dashboardData();
        $hitQueryCount = count(DB::getQueryLog());

        $this->assertGreaterThan($hitQueryCount, $missQueryCount);
    }

    public function test_standalone_page_requests_return_identical_embedded_overview_kpis(): void
    {
        Cache::flush();
        $this->seedSuccessfulWaitingExecution();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $firstHtml = $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->getContent();

        $secondHtml = $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-automation-health-embed', $firstHtml);
        $this->assertSame(
            $this->extractEmbedSection($firstHtml),
            $this->extractEmbedSection($secondHtml),
        );
    }

    public function test_aggregation_cache_uses_sixty_second_ttl_constant(): void
    {
        $constant = (new \ReflectionClass(AutomationHealthService::class))
            ->getReflectionConstant('AGGREGATION_CACHE_TTL_SECONDS');

        $this->assertNotNull($constant);
        $this->assertSame(60, $constant->getValue());
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function normalizeAggregationForAssertion(array $dashboard): array
    {
        $overview = $dashboard['overview'];

        foreach (['last_success_at', 'last_failed_at', 'last_execution_at', 'oldest_pending_started_at'] as $field) {
            $value = $overview[$field] ?? null;
            $overview[$field] = $value instanceof \Illuminate\Support\Carbon
                ? $value->toIso8601String()
                : null;
        }

        $breakdown = array_map(function (array $row): array {
            $value = $row['last_execution_at'] ?? null;
            $row['last_execution_at'] = $value instanceof \Illuminate\Support\Carbon
                ? $value->toIso8601String()
                : null;

            return $row;
        }, $dashboard['breakdown']);

        return [
            'overview' => $overview,
            'breakdown' => $breakdown,
            'failures' => $dashboard['failures'],
        ];
    }

    private function extractEmbedSection(string $html): string
    {
        $start = strpos($html, 'data-automation-health-embed');

        if ($start === false) {
            return '';
        }

        $openTagStart = strrpos(substr($html, 0, $start), '<div');

        if ($openTagStart === false) {
            return '';
        }

        $sectionStart = $openTagStart;
        $depth = 0;
        $length = strlen($html);

        for ($index = $sectionStart; $index < $length; $index++) {
            if (str_starts_with(substr($html, $index), '<div')) {
                $depth++;
            }

            if (str_starts_with(substr($html, $index), '</div')) {
                $depth--;

                if ($depth === 0) {
                    return substr($html, $sectionStart, $index - $sectionStart + 6);
                }
            }
        }

        return '';
    }

    private function seedSuccessfulWaitingExecution(): AutomationExecution
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-CACHE-001',
            'customer_name' => 'Cache Customer',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Cache test incident',
            'description' => 'Automation health cache test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'request_serial',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.health.cache.success',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinutes(2),
        ]);
    }
}
