<?php

namespace Tests\Feature\Retention;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetentionInspectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_command_reports_candidates_without_deleting(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00');

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.command.old',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 10,
            'payload' => ['incoming_email_message_id' => 10],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(30),
            'processed_at' => now()->subDays(30),
        ]);

        $before = OutboxEvent::query()->count();

        $this->artisan('database:retention-inspect', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('read-only; zero database writes')
            ->expectsOutputToContain('completed_outbox')
            ->expectsOutputToContain('No rows were deleted');

        $this->assertSame($before, OutboxEvent::query()->count());

        Carbon::setTestNow();
    }
}
