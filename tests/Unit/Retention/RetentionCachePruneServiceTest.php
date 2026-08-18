<?php

namespace Tests\Unit\Retention;

use App\Services\Retention\RetentionCachePruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionCachePruneServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_delete_expired_cache_rows(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00');

        DB::table('cache')->insert([
            ['key' => 'expired-a', 'value' => str_repeat('x', 100), 'expiration' => now()->subHour()->getTimestamp()],
            ['key' => 'active-a', 'value' => 'fresh', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $summary = app(RetentionCachePruneService::class)->prune(dryRun: true);

        $this->assertTrue($summary->dryRun);
        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame(1, $summary->activeCount);
        $this->assertSame(100, $summary->estimatedCandidatePayloadBytes);
        $this->assertSame(0, $summary->deletedCount);
        $this->assertSame(2, (int) DB::table('cache')->count());

        Carbon::setTestNow();
    }

    public function test_execute_deletes_only_expired_cache_rows_in_batches(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00');

        DB::table('cache')->insert([
            ['key' => 'expired-1', 'value' => 'old-1', 'expiration' => now()->subDays(2)->getTimestamp()],
            ['key' => 'expired-2', 'value' => 'old-2', 'expiration' => now()->subDay()->getTimestamp()],
            ['key' => 'active-1', 'value' => 'fresh', 'expiration' => now()->addDay()->getTimestamp()],
        ]);

        $summary = app(RetentionCachePruneService::class)->prune(
            dryRun: false,
            batchSize: 1,
            limit: 1,
        );

        $this->assertFalse($summary->dryRun);
        $this->assertSame(2, $summary->candidateCount);
        $this->assertSame(1, $summary->deletedCount);
        $this->assertSame(1, $summary->batchesProcessed);
        $this->assertSame(2, (int) DB::table('cache')->count());
        $this->assertDatabaseHas('cache', ['key' => 'active-1']);
        $this->assertDatabaseMissing('cache', ['key' => 'expired-1']);
        $this->assertDatabaseHas('cache', ['key' => 'expired-2']);

        Carbon::setTestNow();
    }
}
