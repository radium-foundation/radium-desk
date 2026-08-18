<?php

namespace Tests\Feature\Retention;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionPruneCommandsTest extends TestCase
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

    public function test_cache_command_defaults_to_dry_run_without_deletes(): void
    {
        DB::table('cache')->insert([
            ['key' => 'expired-command', 'value' => str_repeat('z', 50), 'expiration' => now()->subHour()->getTimestamp()],
            ['key' => 'active-command', 'value' => 'fresh', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->artisan('database:retention-prune-cache')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run — no cache rows will be deleted.')
            ->expectsOutputToContain('Expired candidates')
            ->expectsOutputToContain('No rows were deleted');

        $this->assertSame(2, (int) DB::table('cache')->count());
    }

    public function test_cache_command_execute_deletes_expired_rows_only(): void
    {
        DB::table('cache')->insert([
            ['key' => 'expired-command-exec', 'value' => 'old', 'expiration' => now()->subHour()->getTimestamp()],
            ['key' => 'active-command-exec', 'value' => 'fresh', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->artisan('database:retention-prune-cache', ['--execute' => true, '--limit' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('EXECUTE mode');

        $this->assertDatabaseMissing('cache', ['key' => 'expired-command-exec']);
        $this->assertDatabaseHas('cache', ['key' => 'active-command-exec']);
    }

    public function test_cache_command_rejects_execute_and_dry_run_together(): void
    {
        $this->artisan('database:retention-prune-cache', ['--execute' => true, '--dry-run' => true])
            ->assertFailed()
            ->expectsOutputToContain('not both');
    }

    public function test_outbox_command_defaults_to_dry_run_with_excluded_counts(): void
    {
        $this->seedOutboxRows();

        $this->artisan('database:retention-prune-outbox')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run — no outbox rows will be deleted.')
            ->expectsOutputToContain('Excluded pending')
            ->expectsOutputToContain('Excluded failed')
            ->expectsOutputToContain('email.inbound.process')
            ->expectsOutputToContain('No rows were deleted');

        $this->assertSame(3, OutboxEvent::query()->count());
    }

    public function test_outbox_command_execute_deletes_old_completed_rows_only(): void
    {
        $this->seedOutboxRows();

        $this->artisan('database:retention-prune-outbox', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(2, OutboxEvent::query()->count());
        $this->assertSame(0, OutboxEvent::query()->where('idempotency_key', 'prune.cmd.old')->count());
        $this->assertSame(1, OutboxEvent::query()->where('idempotency_key', 'prune.cmd.recent')->count());
        $this->assertSame(1, OutboxEvent::query()->where('idempotency_key', 'prune.cmd.failed')->count());
    }

    public function test_outbox_command_rejects_execute_and_dry_run_together(): void
    {
        $this->artisan('database:retention-prune-outbox', ['--execute' => true, '--dry-run' => true])
            ->assertFailed()
            ->expectsOutputToContain('not both');
    }

    private function seedOutboxRows(): void
    {
        OutboxEvent::query()->create([
            'idempotency_key' => 'prune.cmd.old',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 101,
            'payload' => ['incoming_email_message_id' => 101],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(30),
            'processed_at' => now()->subDays(30),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'prune.cmd.recent',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 102,
            'payload' => ['incoming_email_message_id' => 102],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(3),
            'processed_at' => now()->subDays(3),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'prune.cmd.failed',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 103,
            'payload' => ['incoming_email_message_id' => 103],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 2,
            'available_at' => now()->subDays(30),
        ]);
    }
}
