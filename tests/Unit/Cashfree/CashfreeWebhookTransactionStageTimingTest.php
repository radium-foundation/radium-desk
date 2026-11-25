<?php

namespace Tests\Unit\Cashfree;

use App\Services\Cashfree\CashfreeWebhookStageTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CashfreeWebhookTransactionStageTimingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $capturedStageLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturedStageLogs = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if ($event->level !== 'info' || $event->message !== CashfreeWebhookStageTiming::LOG_EVENT) {
                return;
            }

            if (! is_array($event->context)) {
                return;
            }

            $this->capturedStageLogs[] = $event->context;
        });
    }

    public function test_transaction_persistence_covers_full_callback_despite_nested_measure_stage(): void
    {
        $timing = app(CashfreeWebhookStageTiming::class);
        $timing->activate();

        DB::transaction(function () use ($timing): void {
            $timing->beginTransactionPersistence();

            usleep(15_000);
            $timing->measureStage('outbox_writes', function (): void {
                usleep(25_000);
            });
            usleep(10_000);

            $timing->markTransactionPersistenceComplete();
        });

        $timing->markTransactionCommitComplete();

        $persistence = $this->durationForStage('transaction_persistence');
        $outbox = $this->durationForStage('outbox_writes');
        $commit = $this->durationForStage('transaction_commit');

        $this->assertGreaterThan(45.0, $persistence, 'persistence must include nested callback work');
        $this->assertGreaterThan(20.0, $outbox, 'inner stage keeps its own interval');
        $this->assertGreaterThan($outbox + 15.0, $persistence, 'persistence must exceed a single inner stage');
        $this->assertGreaterThanOrEqual(0.0, $commit);
    }

    public function test_transaction_commit_measures_only_post_callback_commit_return_interval(): void
    {
        $timing = app(CashfreeWebhookStageTiming::class);
        $timing->activate();

        DB::transaction(function () use ($timing): void {
            $timing->beginTransactionPersistence();
            usleep(5_000);
            $timing->markTransactionPersistenceComplete();
            usleep(40_000);
        });

        $timing->markTransactionCommitComplete();

        $persistence = $this->durationForStage('transaction_persistence');
        $commit = $this->durationForStage('transaction_commit');

        $this->assertGreaterThan(3.0, $persistence);
        $this->assertLessThan(15.0, $persistence);
        $this->assertGreaterThan(30.0, $commit, 'commit must not include callback body waits');
    }

    public function test_rollback_path_does_not_emit_transaction_commit(): void
    {
        $timing = app(CashfreeWebhookStageTiming::class);
        $timing->activate();

        try {
            DB::transaction(function () use ($timing): void {
                $timing->beginTransactionPersistence();
                usleep(5_000);
                $timing->markTransactionPersistenceComplete('error');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $timing->markTransactionCommitComplete();

        $stages = collect($this->capturedStageLogs)->pluck('stage')->all();
        $this->assertContains('transaction_persistence', $stages);
        $this->assertNotContains('transaction_commit', $stages);

        $persistence = collect($this->capturedStageLogs)->firstWhere('stage', 'transaction_persistence');
        $this->assertIsArray($persistence);
        $this->assertSame('error', $persistence['outcome']);
    }

    public function test_transaction_stage_logs_remain_allowlisted(): void
    {
        $timing = app(CashfreeWebhookStageTiming::class);
        $timing->activate();

        DB::transaction(function () use ($timing): void {
            $timing->beginTransactionPersistence();
            $timing->markTransactionPersistenceComplete();
        });
        $timing->markTransactionCommitComplete();

        $allowed = [
            'event',
            'request_correlation_id',
            'webhook_log_id',
            'stage',
            'duration_ms',
            'outcome',
            'retry_attempt',
            'retry_count',
            'listener_enqueue_count',
            'http_status',
            'error_category',
        ];

        foreach ($this->capturedStageLogs as $context) {
            foreach (array_keys($context) as $key) {
                $this->assertContains($key, $allowed, "Unexpected log key: {$key}");
            }
        }
    }

    private function durationForStage(string $stage): float
    {
        $row = collect($this->capturedStageLogs)->firstWhere('stage', $stage);
        $this->assertIsArray($row);

        return (float) ($row['duration_ms'] ?? 0);
    }
}
