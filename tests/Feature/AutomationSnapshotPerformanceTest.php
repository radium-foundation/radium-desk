<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AutomationOperationsSnapshotService;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationSnapshotPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
    }

    public function test_snapshot_refresh_stays_under_query_budget_with_active_cases(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $ref = app(IncidentReferenceService::class);

        for ($i = 0; $i < 120; $i++) {
            $order = Order::query()->create([
                'order_id' => 'RB-SNAP-PERF-'.$i.'-'.uniqid(),
                'serial_number' => $i % 4 === 0 ? null : ('FPSPL1141'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)),
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'status' => 'active',
                'created_by' => $actor->id,
                'created_at' => now()->subMinutes($i + 1),
                'updated_at' => now()->subMinutes($i + 1),
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $ref->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Snapshot perf '.$i,
                'description' => 'Snapshot perf',
                'status' => IncidentStatus::Open,
                'created_by' => $actor->id,
                'created_at' => now()->subMinutes($i + 1),
                'updated_at' => now()->subMinutes($i + 1),
            ]);
        }

        for ($i = 0; $i < 40; $i++) {
            CashfreeWebhookLog::query()->create([
                'cf_payment_id' => 'pay-snap-'.$i,
                'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
                'request_headers' => [],
                'request_payload' => [
                    'data' => [
                        'order' => ['order_id' => 'CF-SNAP-'.$i],
                        'payment' => [
                            'payment_status' => 'SUCCESS',
                            'cf_payment_id' => 'pay-snap-'.$i,
                        ],
                    ],
                ],
                'received_at' => now()->subMinutes($i),
                'processed_at' => now()->subMinutes($i),
            ]);
        }

        $service = app(AutomationOperationsSnapshotService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $first = $service->refreshDetailed();
        $firstMs = (hrtime(true) - $started) / 1e6;
        $firstQueries = count(DB::getQueryLog());

        $this->assertTrue($first['rebuilt']);
        $this->assertLessThan(
            400,
            $firstQueries,
            "Expected full rebuild under 400 SQL queries, got {$firstQueries} in ".round($firstMs, 1).'ms',
        );

        $existsPaymentChecks = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'cashfree_payment_id'))
            ->count();

        $this->assertLessThan(
            20,
            $existsPaymentChecks,
            "Expected batched Cashfree payment lookups, got {$existsPaymentChecks} cashfree_payment_id queries",
        );

        Cache::forget(CashfreeWebhookReliabilityMetrics::SNAPSHOT_CACHE_KEY);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $second = $service->refreshDetailed();
        $secondMs = (hrtime(true) - $started) / 1e6;
        $secondQueries = count(DB::getQueryLog());

        $this->assertFalse($second['rebuilt']);
        $this->assertLessThan(
            40,
            $secondQueries,
            "Expected incremental refresh under 40 SQL queries, got {$secondQueries} in ".round($secondMs, 1).'ms',
        );
        $this->assertSame(
            $first['snapshot']->healthCounts['automation_pending'] ?? null,
            $second['snapshot']->healthCounts['automation_pending'] ?? null,
        );
    }
}
