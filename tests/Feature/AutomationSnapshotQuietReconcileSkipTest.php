<?php

namespace Tests\Feature;

use App\Enums\AutomationSnapshotSlice;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\AutomationOperationsSnapshotInvalidator;
use App\Services\AutomationOperationsSnapshotBuilder;
use App\Services\AutomationOperationsSnapshotService;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAutomationHealthService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Fingerprint-first quiet --reconcile skip: avoid activeIncidents() hydrate when
 * dirty=[] and contentFingerprint still matches the cached meta.
 */
class AutomationSnapshotQuietReconcileSkipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
    }

    public function test_clean_matching_fingerprint_skips_active_incident_hydrate(): void
    {
        $this->seedActiveCase();
        $service = app(AutomationOperationsSnapshotService::class);

        $seed = $service->refreshDetailed(forceReconcile: true);
        $this->assertTrue($seed['rebuilt']);
        $this->assertSame('reconcile', $seed['mode']);

        $health = Mockery::mock(app(ServiceCaseAutomationHealthService::class))->makePartial();
        $health->shouldReceive('activeIncidentsForAutomationSnapshot')->never();
        $health->shouldReceive('activeIncidents')->never();
        $this->app->instance(ServiceCaseAutomationHealthService::class, $health);
        $this->app->forgetInstance(AutomationOperationsSnapshotBuilder::class);
        $this->app->forgetInstance(AutomationOperationsSnapshotService::class);
        $service = app(AutomationOperationsSnapshotService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $quiet = $service->refreshDetailed(forceReconcile: true);
        $ms = (hrtime(true) - $started) / 1e6;
        $queries = count(DB::getQueryLog());

        $this->assertFalse($quiet['rebuilt']);
        $this->assertSame('reconcile-skip', $quiet['mode']);
        $this->assertSame($seed['fingerprint'], $quiet['fingerprint']);
        $this->assertSame([], $quiet['dirty_slices']);
        $this->assertLessThan(
            40,
            $queries,
            "Expected quiet reconcile under 40 SQL queries, got {$queries} in ".round($ms, 1).'ms',
        );
    }

    public function test_dirty_health_still_forces_full_rebuild(): void
    {
        $this->seedActiveCase();
        $service = app(AutomationOperationsSnapshotService::class);
        $invalidator = app(AutomationOperationsSnapshotInvalidator::class);

        $seed = $service->refreshDetailed(forceReconcile: true);
        $this->assertTrue($seed['rebuilt']);
        $this->assertSame('reconcile-skip', $service->refreshDetailed(forceReconcile: true)['mode']);

        $invalidator->markDirty(AutomationSnapshotSlice::Health);
        $this->assertTrue($invalidator->isDirty());

        $result = $service->refreshDetailed(forceReconcile: true);

        $this->assertTrue($result['rebuilt']);
        $this->assertSame('reconcile', $result['mode']);
        $this->assertContains(AutomationSnapshotSlice::Health->value, $result['dirty_slices']);
        $this->assertFalse($invalidator->isDirty());
    }

    public function test_fingerprint_mismatch_forces_full_rebuild(): void
    {
        $this->seedActiveCase();
        $service = app(AutomationOperationsSnapshotService::class);

        $seed = $service->refreshDetailed(forceReconcile: true);
        $this->assertTrue($seed['rebuilt']);

        $meta = Cache::get(AutomationOperationsSnapshotService::META_CACHE_KEY);
        $this->assertIsArray($meta);
        $meta['fingerprint'] = 'stale-fingerprint-for-mismatch-test';
        Cache::put(
            AutomationOperationsSnapshotService::META_CACHE_KEY,
            $meta,
            AutomationOperationsSnapshotService::TTL_SECONDS,
        );

        $result = $service->refreshDetailed(forceReconcile: true);

        $this->assertTrue($result['rebuilt']);
        $this->assertSame('reconcile', $result['mode']);
        $this->assertNotSame('stale-fingerprint-for-mismatch-test', $result['fingerprint']);
    }

    public function test_hard_reconcile_cadence_forces_full_rebuild_even_when_clean(): void
    {
        $this->seedActiveCase();
        $service = app(AutomationOperationsSnapshotService::class);

        $seed = $service->refreshDetailed(forceReconcile: true);
        $this->assertTrue($seed['rebuilt']);

        $meta = Cache::get(AutomationOperationsSnapshotService::META_CACHE_KEY);
        $this->assertIsArray($meta);
        $meta['built_at'] = now()
            ->subSeconds(AutomationOperationsSnapshotService::HARD_RECONCILE_SECONDS + 5)
            ->toIso8601String();
        Cache::put(
            AutomationOperationsSnapshotService::META_CACHE_KEY,
            $meta,
            AutomationOperationsSnapshotService::TTL_SECONDS,
        );

        $result = $service->refreshDetailed(forceReconcile: true);

        $this->assertTrue($result['rebuilt']);
        $this->assertSame('reconcile', $result['mode']);
    }

    public function test_quiet_skip_preserves_snapshot_payload_equivalence(): void
    {
        $this->seedActiveCase();
        $service = app(AutomationOperationsSnapshotService::class);

        $seed = $service->refreshDetailed(forceReconcile: true);
        $before = $seed['snapshot']->toCacheArray();

        $quiet = $service->refreshDetailed(forceReconcile: true);
        $this->assertSame('reconcile-skip', $quiet['mode']);
        $after = $quiet['snapshot']->toCacheArray();

        // Time-dependent age strings may refresh; core KPI / queue contracts stay equivalent.
        $this->assertSame(
            $before['healthCounts']['automation_pending'] ?? null,
            $after['healthCounts']['automation_pending'] ?? null,
        );
        $this->assertSame(
            $before['healthCounts']['validation_failed'] ?? null,
            $after['healthCounts']['validation_failed'] ?? null,
        );
        $this->assertSame($before['duplicateSerialConflicts'], $after['duplicateSerialConflicts']);
        $this->assertSame($before['radiumBoxNotFoundQueue'], $after['radiumBoxNotFoundQueue']);
        $this->assertSame($before['repairStatistics'], $after['repairStatistics']);
        $this->assertSame($before['validationByCategory'], $after['validationByCategory']);
        $this->assertSame($before['validationByProduct'], $after['validationByProduct']);
        $this->assertSame($before['validationByValidatorRule'], $after['validationByValidatorRule']);
        $this->assertCount(
            count($before['waitingForCustomerSerialQueue'] ?? []),
            $after['waitingForCustomerSerialQueue'] ?? [],
        );
    }

    public function test_invalidation_after_quiet_skip_still_causes_rebuild(): void
    {
        $this->seedActiveCase();
        $service = app(AutomationOperationsSnapshotService::class);
        $invalidator = app(AutomationOperationsSnapshotInvalidator::class);

        $service->refreshDetailed(forceReconcile: true);
        $quiet = $service->refreshDetailed(forceReconcile: true);
        $this->assertSame('reconcile-skip', $quiet['mode']);

        $invalidator->markCaseOrOrderChanged();
        $this->assertTrue($invalidator->isDirty());

        $result = $service->refreshDetailed(forceReconcile: true);

        $this->assertTrue($result['rebuilt']);
        $this->assertSame('reconcile', $result['mode']);
        $this->assertContains(AutomationSnapshotSlice::Health->value, $result['dirty_slices']);
        $this->assertFalse($invalidator->isDirty());
    }

    public function test_reconcile_command_reports_quiet_skip_mode(): void
    {
        $this->seedActiveCase();
        app(AutomationOperationsSnapshotService::class)->refreshDetailed(forceReconcile: true);

        $this->artisan('automation:snapshot', ['--reconcile' => true])
            ->assertSuccessful()
            ->expectsOutput('Automation operations snapshot reconciled (quiet skip; fingerprint matched).');
    }

    public function test_cold_manual_reconcile_still_full_rebuilds(): void
    {
        $this->seedActiveCase();
        Cache::flush();

        $result = app(AutomationOperationsSnapshotService::class)->refreshDetailed(forceReconcile: true);

        $this->assertTrue($result['rebuilt']);
        $this->assertSame('reconcile', $result['mode']);
    }

    public function test_benchmark_quiet_vs_dirty_reconcile(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $ref = app(IncidentReferenceService::class);

        for ($i = 0; $i < 60; $i++) {
            $order = Order::query()->create([
                'order_id' => 'RB-QB-'.$i.'-'.uniqid(),
                'serial_number' => $i % 4 === 0 ? null : ('FPSPL1142'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)),
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'status' => 'active',
                'created_by' => $actor->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $ref->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Quiet bench '.$i,
                'description' => 'Quiet bench',
                'status' => IncidentStatus::Open,
                'created_by' => $actor->id,
            ]);
        }

        $service = app(AutomationOperationsSnapshotService::class);
        $invalidator = app(AutomationOperationsSnapshotInvalidator::class);

        $measure = function (callable $fn): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            $result = $fn();
            $ms = (hrtime(true) - $started) / 1e6;
            $queries = DB::getQueryLog();
            $hydrateLike = 0;

            foreach ($queries as $query) {
                $sql = strtolower((string) $query['query']);
                if (
                    (str_contains($sql, 'from `incidents`') || str_contains($sql, 'from "incidents"'))
                    && ! str_contains($sql, 'count(*)')
                    && ! str_contains($sql, 'max(')
                ) {
                    $hydrateLike++;
                }
            }

            return [
                'ms' => round($ms, 1),
                'sql' => count($queries),
                'hydrate_like' => $hydrateLike,
                'rebuilt' => (bool) ($result['rebuilt'] ?? false),
                'mode' => (string) ($result['mode'] ?? ''),
            ];
        };

        $cold = $measure(fn (): array => $service->refreshDetailed(forceReconcile: true));
        $quiet = $measure(fn (): array => $service->refreshDetailed(forceReconcile: true));
        $invalidator->markDirty(AutomationSnapshotSlice::Health);
        $dirty = $measure(fn (): array => $service->refreshDetailed(forceReconcile: true));

        fwrite(STDERR, "\nQUIET_RECONCILE_BENCH ".json_encode([
            'cold' => $cold,
            'quiet' => $quiet,
            'dirty' => $dirty,
        ], JSON_UNESCAPED_SLASHES)."\n");

        $this->assertTrue($cold['rebuilt']);
        $this->assertSame('reconcile', $cold['mode']);
        $this->assertFalse($quiet['rebuilt']);
        $this->assertSame('reconcile-skip', $quiet['mode']);
        $this->assertSame(0, $quiet['hydrate_like']);
        $this->assertTrue($dirty['rebuilt']);
        $this->assertSame('reconcile', $dirty['mode']);
        $this->assertGreaterThan(0, $dirty['hydrate_like']);
        $this->assertLessThan($cold['sql'], $quiet['sql']);
        $this->assertLessThan($dirty['sql'], $quiet['sql']);
    }

    private function seedActiveCase(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RB-QS-'.uniqid(),
            'serial_number' => 'FPSPL1141999',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Quiet skip seed',
            'description' => 'Quiet skip seed',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
    }
}
