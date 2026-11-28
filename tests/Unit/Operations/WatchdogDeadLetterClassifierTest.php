<?php

namespace Tests\Unit\Operations;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\WatchdogDeadLetterClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchdogDeadLetterClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ira.watchdog.actionable_hours' => 48,
            'ira.watchdog.stale_days' => 30,
        ]);
    }

    public function test_old_failed_job_counts_as_historical_not_actionable(): void
    {
        $order = $this->createFailedOrder('RB900301', now());
        $this->insertEnrichmentFailedJob('uuid-historical-dl', $order->id, now()->subDays(45));

        $summary = app(WatchdogDeadLetterClassifier::class)->summarize(now());

        $this->assertSame(1, $summary['legacy_total']);
        $this->assertSame(0, $summary['actionable_count']);
        $this->assertSame(1, $summary['historical_count']);
    }

    public function test_recent_not_synced_failed_job_is_actionable(): void
    {
        $order = $this->createFailedOrder('RB900302', now());
        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced]);
        $this->insertEnrichmentFailedJob('uuid-actionable-dl', $order->id, now()->subHours(2));

        $summary = app(WatchdogDeadLetterClassifier::class)->summarize(now());

        $this->assertSame(1, $summary['actionable_count']);
        $this->assertSame(1, $summary['legacy_total']);
    }

    public function test_synced_order_failed_job_is_recovered(): void
    {
        $order = $this->createFailedOrder('RB900303', now());
        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced]);
        $this->insertEnrichmentFailedJob('uuid-recovered-dl', $order->id, now()->subHours(2));

        $summary = app(WatchdogDeadLetterClassifier::class)->summarize(now());

        $this->assertSame(1, $summary['recovered_count']);
        $this->assertSame(0, $summary['actionable_count']);
    }

    private function createFailedOrder(string $orderId, Carbon $lastSyncAt): Order
    {
        $actor = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'DLQ Classifier Test',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => $lastSyncAt,
        ]);
    }

    private function insertEnrichmentFailedJob(string $uuid, int $orderId, Carbon $failedAt): void
    {
        $serialized = 'O:36:"App\\Jobs\\RadiumBoxOrderEnrichmentJob":1:{s:7:"orderId";i:'.$orderId.';}';

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'critical',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RadiumBoxOrderEnrichmentJob',
                'data' => ['command' => $serialized],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'MaxAttemptsExceededException',
            'failed_at' => $failedAt,
        ]);
    }
}
