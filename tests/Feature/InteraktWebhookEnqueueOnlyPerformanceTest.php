<?php

namespace Tests\Feature;

use App\Enums\OutboxEventStatus;
use App\Models\InteraktWebhookLog;
use App\Models\OutboxEvent;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Interakt\InteraktWebhookOutboxWriter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class InteraktWebhookEnqueueOnlyPerformanceTest extends TestCase
{
    use InteractsWithInteraktWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.verify_signature' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_webhook_ack_stays_under_budget_and_does_not_drain_unrelated_outbox(): void
    {
        // Unrelated pending work that global process() would have drained in-request.
        OutboxEvent::query()->create([
            'idempotency_key' => 'cashfree.unrelated.phase2',
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 999001,
            'payload' => ['webhook_log_id' => 999001],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now()->subMinute(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);

        $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload())
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $queryCount = count(DB::getQueryLog());

        $this->assertLessThan(
            100,
            $elapsedMs,
            "Expected Interakt webhook ack <100ms, got {$elapsedMs}ms with {$queryCount} queries",
        );

        $this->assertLessThan(
            25,
            $queryCount,
            "Expected enqueue-only webhook to stay lean, got {$queryCount} queries in {$elapsedMs}ms",
        );

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'processing_status' => InteraktWebhookLog::STATUS_RECEIVED,
        ]);

        $this->assertSame(
            OutboxEventStatus::Pending,
            OutboxEvent::query()->where('idempotency_key', 'cashfree.unrelated.phase2')->value('status'),
        );

        $this->assertSame(
            OutboxEventStatus::Pending,
            OutboxEvent::query()
                ->where('event_type', InteraktWebhookOutboxWriter::EVENT_TYPE)
                ->value('status'),
        );
    }
}
