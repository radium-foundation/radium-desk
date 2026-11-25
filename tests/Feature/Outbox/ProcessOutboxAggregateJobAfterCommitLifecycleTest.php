<?php

namespace Tests\Feature\Outbox;

use App\Infrastructure\Queue\QueueRouting;
use App\Jobs\Outbox\ProcessOutboxAggregateJob;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real Laravel afterCommit() + database queue persistence (no Queue::fake()).
 *
 * Opts out of the PHPUnit RefreshDatabase transaction wrapper so nested
 * transactions and commit callbacks behave like production.
 */
class ProcessOutboxAggregateJobAfterCommitLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    private const AGGREGATE_ID = 424242;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('jobs')) {
            RefreshDatabaseState::$migrated = false;
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = true;
        }

        $connectionName = (string) config('database.default');
        $transactionsManager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $transactionsManager);
        DB::connection($connectionName)->setTransactionManager($transactionsManager);

        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 90,
            'queue.connections.database.after_commit' => false,
        ]);

        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->delete();
        }
    }

    private function dispatchProductionPath(): void
    {
        ProcessOutboxAggregateJob::dispatch(
            CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            self::AGGREGATE_ID,
        )->afterCommit();
    }

    private function criticalJobCount(): int
    {
        return (int) DB::table('jobs')->where('queue', QueueRouting::critical())->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function latestCriticalJobPayload(): array
    {
        $row = DB::table('jobs')
            ->where('queue', QueueRouting::critical())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);

        $decoded = json_decode((string) $row->payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function test_uncommitted_dispatch_is_invisible_and_rollback_leaves_zero_jobs(): void
    {
        DB::beginTransaction();

        $this->dispatchProductionPath();

        $this->assertSame(0, $this->criticalJobCount());
        $this->assertSame(0, (int) DB::table('jobs')->count());

        DB::rollBack();

        $this->assertSame(0, $this->criticalJobCount());
        $this->assertSame(0, (int) DB::table('jobs')->count());
    }

    public function test_commit_persists_critical_queue_job_with_aggregate_identity(): void
    {
        DB::beginTransaction();

        $this->dispatchProductionPath();

        $this->assertSame(0, $this->criticalJobCount());

        DB::commit();

        $this->assertSame(1, $this->criticalJobCount());

        $row = DB::table('jobs')->where('queue', QueueRouting::critical())->firstOrFail();

        $this->assertNull($row->reserved_at, 'Job must not be reserved before a worker runs it');

        $payload = $this->latestCriticalJobPayload();

        $this->assertStringContainsString(
            ProcessOutboxAggregateJob::class,
            (string) ($payload['displayName'] ?? ''),
        );

        $command = unserialize((string) ($payload['data']['command'] ?? ''));
        $this->assertInstanceOf(ProcessOutboxAggregateJob::class, $command);
        $this->assertSame(CashfreeWebhookOutboxWriter::AGGREGATE_TYPE, $command->aggregateType);
        $this->assertSame(self::AGGREGATE_ID, $command->aggregateId);
        $this->assertSame(QueueRouting::critical(), $command->queue);
    }
}
