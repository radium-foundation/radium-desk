<?php

namespace Tests\Unit\Retention;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use App\Services\Retention\RetentionOutboxPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetentionOutboxPruneServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_reports_candidates_and_excludes_non_eligible_statuses(): void
    {
        $this->seedOutboxFixtures();

        $summary = app(RetentionOutboxPruneService::class)->prune(dryRun: true);

        $this->assertTrue($summary->dryRun);
        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame(['email.inbound.process' => 1], $summary->candidatesByEventType);
        $this->assertSame(1, $summary->excludedPending);
        $this->assertSame(1, $summary->excludedProcessing);
        $this->assertSame(1, $summary->excludedFailed);
        $this->assertSame(1, $summary->excludedRecentCompleted);
        $this->assertSame(1, $summary->excludedNullProcessedAt);
        $this->assertSame(6, $summary->tableTotalCount);
        $this->assertSame(6, OutboxEvent::query()->count());
    }

    public function test_execute_deletes_only_old_completed_rows_with_processed_at(): void
    {
        $this->seedOutboxFixtures();

        $summary = app(RetentionOutboxPruneService::class)->prune(dryRun: false, batchSize: 1);

        $this->assertFalse($summary->dryRun);
        $this->assertSame(1, $summary->deletedCount);
        $this->assertSame(5, OutboxEvent::query()->count());
        $this->assertSame(0, OutboxEvent::query()->where('idempotency_key', 'retention.outbox.old')->count());
        $this->assertSame(1, OutboxEvent::query()->where('idempotency_key', 'retention.outbox.recent')->count());
        $this->assertSame(1, OutboxEvent::query()->where('status', OutboxEventStatus::Failed)->count());
        $this->assertSame(1, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
        $this->assertSame(1, OutboxEvent::query()->where('status', OutboxEventStatus::Processing)->count());
        $this->assertSame(1, OutboxEvent::query()->where('idempotency_key', 'retention.outbox.null-processed')->count());
    }

    private function seedOutboxFixtures(): void
    {
        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.outbox.old',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 1,
            'payload' => ['incoming_email_message_id' => 1],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(30),
            'processed_at' => now()->subDays(30),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.outbox.recent',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 2,
            'payload' => ['incoming_email_message_id' => 2],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(5),
            'processed_at' => now()->subDays(5),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.outbox.pending',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 3,
            'payload' => ['incoming_email_message_id' => 3],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.outbox.processing',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 4,
            'payload' => ['incoming_email_message_id' => 4],
            'status' => OutboxEventStatus::Processing,
            'attempts' => 1,
            'available_at' => now(),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.outbox.failed',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 5,
            'payload' => ['incoming_email_message_id' => 5],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 3,
            'available_at' => now()->subDays(30),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.outbox.null-processed',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 6,
            'payload' => ['incoming_email_message_id' => 6],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(30),
            'processed_at' => null,
        ]);
    }
}
