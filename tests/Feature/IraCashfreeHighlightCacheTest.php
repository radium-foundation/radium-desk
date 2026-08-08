<?php

namespace Tests\Feature;

use App\Data\Operations\CashfreeDeviceEnrichmentQualitySummary;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Data\Operations\MissingSerialAutomationQualitySummary;
use App\Services\Operations\IraBriefingFormatter;
use App\Services\Operations\OperationsCashfreeDeviceEnrichmentService;
use App\Services\Operations\OperationsCashfreeHealthService;
use App\Services\Operations\OperationsMissingSerialAutomationService;
use App\Services\Operations\RuleBasedReasoningProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class IraCashfreeHighlightCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_cache_hit_uses_cached_cashfree_scalars_without_calling_widget(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $health = Mockery::mock(OperationsCashfreeHealthService::class);
        $health->shouldNotReceive('widget');
        $health->shouldReceive('cachedWidget')
            ->once()
            ->andReturn([
                'is_healthy' => true,
                'historical_resolved_failures' => 7,
                'paid_without_desk_order' => 0,
                'active_failed_webhooks' => 0,
            ]);

        $briefing = $this->provider($health)->generateBriefing(
            $this->snapshot(),
            null,
            [],
            [],
            now(),
        );

        $this->assertTrue(
            collect($briefing->highlights)->contains(
                fn (string $line): bool => $line === 'Cashfree healthy. 7 historical failure(s) archived.',
            ),
        );
        $this->assertTrue(
            collect($briefing->highlights)->contains(
                fn (string $line): bool => str_contains($line, 'case(s) need action today'),
            ),
        );
    }

    public function test_cache_miss_omits_cashfree_highlight_without_calling_widget(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $health = Mockery::mock(OperationsCashfreeHealthService::class);
        $health->shouldNotReceive('widget');
        $health->shouldReceive('cachedWidget')->once()->andReturn(null);

        $briefing = $this->provider($health)->generateBriefing(
            $this->snapshot([
                'action_required' => 2,
                'attention' => 1,
                'scheduled' => 4,
                'overdue' => 1,
                'warning' => 0,
                'open_cases' => 3,
            ]),
            null,
            [],
            [],
            now(),
        );

        $this->assertFalse(
            collect($briefing->highlights)->contains(
                fn (string $line): bool => str_contains($line, 'Cashfree'),
            ),
        );
        $this->assertContains('3 case(s) need action today', $briefing->highlights);
        $this->assertContains('4 appointment(s) scheduled', $briefing->highlights);
        $this->assertContains('1 case requires action', $briefing->highlights);
    }

    public function test_cached_widget_reads_existing_cache_key_without_rebuild(): void
    {
        Cache::flush();

        Cache::put(OperationsCashfreeHealthService::CACHE_KEY, [
            'is_healthy' => false,
            'paid_without_desk_order' => 2,
            'active_failed_webhooks' => 1,
            'historical_resolved_failures' => 0,
            'oldest_failed_at' => null,
            'newest_failed_at' => null,
            'last_successful_webhook_at' => null,
            'last_failed_webhook_at' => null,
            'latest_webhook_at' => null,
        ], now()->addMinute());

        $cached = app(OperationsCashfreeHealthService::class)->cachedWidget();

        $this->assertIsArray($cached);
        $this->assertFalse($cached['is_healthy']);
        $this->assertSame(2, $cached['paid_without_desk_order']);
        $this->assertSame(1, $cached['active_failed_webhooks']);

        Cache::forget(OperationsCashfreeHealthService::CACHE_KEY);

        $this->assertNull(app(OperationsCashfreeHealthService::class)->cachedWidget());
    }

    public function test_cached_widget_miss_performs_zero_sql_and_never_rebuilds(): void
    {
        Cache::flush();

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $miss = app(OperationsCashfreeHealthService::class)->cachedWidget();

        $this->assertNull($miss);
        $this->assertSame(
            0,
            count(\Illuminate\Support\Facades\DB::getQueryLog()),
            'cachedWidget() cache miss must not run Cashfree integrity SQL.',
        );
        $this->assertFalse(Cache::has(OperationsCashfreeHealthService::CACHE_KEY));
    }

    public function test_widget_still_builds_and_seeds_cache_on_miss(): void
    {
        Cache::flush();

        $widget = app(OperationsCashfreeHealthService::class)->widget(useCache: true);

        $this->assertIsArray($widget);
        $this->assertArrayHasKey('is_healthy', $widget);
        $this->assertTrue(Cache::has(OperationsCashfreeHealthService::CACHE_KEY));

        $cached = app(OperationsCashfreeHealthService::class)->cachedWidget();
        $this->assertIsArray($cached);
        $this->assertSame($widget['is_healthy'], $cached['is_healthy']);
        $this->assertSame($widget['paid_without_desk_order'], $cached['paid_without_desk_order']);
    }

    public function test_cache_hit_via_real_cache_key_surfaces_unhealthy_highlight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
        Cache::flush();

        Cache::put(OperationsCashfreeHealthService::CACHE_KEY, [
            'is_healthy' => false,
            'paid_without_desk_order' => 3,
            'active_failed_webhooks' => 0,
            'historical_resolved_failures' => 0,
            'oldest_failed_at' => null,
            'newest_failed_at' => null,
            'last_successful_webhook_at' => null,
            'last_failed_webhook_at' => null,
            'latest_webhook_at' => null,
        ], now()->addMinute());

        $device = Mockery::mock(OperationsCashfreeDeviceEnrichmentService::class);
        $device->shouldReceive('qualitySummary')->andReturn(new CashfreeDeviceEnrichmentQualitySummary(0, 0, 0));

        $serial = Mockery::mock(OperationsMissingSerialAutomationService::class);
        $serial->shouldReceive('qualitySummary')->andReturn(new MissingSerialAutomationQualitySummary(0, 0, 0, 0));

        $provider = new RuleBasedReasoningProvider(
            app(IraBriefingFormatter::class),
            $device,
            $serial,
            app(OperationsCashfreeHealthService::class),
        );

        $briefing = $provider->generateBriefing($this->snapshot(), null, [], [], now());

        $this->assertTrue(
            collect($briefing->highlights)->contains(
                fn (string $line): bool => $line === '3 paid Cashfree payment(s) missing Desk orders.',
            ),
        );
    }

    /**
     * @param  array<string, int>  $operations
     */
    private function snapshot(array $operations = []): IraOperationalSnapshotData
    {
        return new IraOperationalSnapshotData(
            date: '2026-07-06',
            operations: array_merge([
                'action_required' => 1,
                'attention' => 0,
                'scheduled' => 2,
                'overdue' => 0,
                'warning' => 0,
                'open_cases' => 1,
            ], $operations),
            team: ['available' => 1],
            performance: ['completed_cases' => 0],
        );
    }

    private function provider(OperationsCashfreeHealthService $health): RuleBasedReasoningProvider
    {
        $device = Mockery::mock(OperationsCashfreeDeviceEnrichmentService::class);
        $device->shouldReceive('qualitySummary')->andReturn(new CashfreeDeviceEnrichmentQualitySummary(0, 0, 0));

        $serial = Mockery::mock(OperationsMissingSerialAutomationService::class);
        $serial->shouldReceive('qualitySummary')->andReturn(new MissingSerialAutomationQualitySummary(0, 0, 0, 0));

        return new RuleBasedReasoningProvider(
            app(IraBriefingFormatter::class),
            $device,
            $serial,
            $health,
        );
    }
}
