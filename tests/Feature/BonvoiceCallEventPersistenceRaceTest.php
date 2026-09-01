<?php

namespace Tests\Feature;

use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceCallEventStore;
use App\Services\Bonvoice\BonvoiceCallEventWriteContention;
use App\Services\Bonvoice\BonvoiceWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class BonvoiceCallEventPersistenceRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.verify_webhook_auth' => false,
            'bonvoice.require_bearer' => false,
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.missed_call_recovery_enabled' => false,
            'bonvoice.call_event_write_retry.max_attempts' => 3,
            'bonvoice.call_event_write_retry.sleep_milliseconds' => 0,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_lifecycle_upserts_keep_one_row_per_call_id_and_leg(): void
    {
        $callId = 'call-race-unique-001';

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0', eventId: 'e0'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0.5', eventId: 'e05'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '1', eventId: 'e1'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '2', status: 'ANSWERED', eventId: 'e2'))->assertOk();

        $this->assertSame(1, BonvoiceCallEvent::query()->where('call_id', $callId)->count());
        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => $callId,
            'leg' => 'call',
            'call_type' => '2',
            'status' => 'ANSWERED',
        ]);
        $this->assertSame(
            BonvoiceWebhookProcessorService::STATUS_PROCESSED,
            BonvoiceWebhookLog::query()->latest('id')->value('processing_status'),
        );
    }

    public function test_later_lifecycle_is_not_overwritten_by_late_ringing(): void
    {
        $callId = 'call-race-hangup-001';

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '2', status: 'NOANSWER', eventId: 'hangup'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0.5', eventId: 'late'))->assertOk();

        $this->assertSame(1, BonvoiceCallEvent::query()->where('call_id', $callId)->count());
        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => $callId,
            'call_type' => '2',
            'status' => 'NOANSWER',
            'event_id' => 'hangup',
        ]);
    }

    public function test_distinct_call_ids_persist_independently(): void
    {
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload('call-a-001', '0.5', eventId: 'a'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload('call-b-001', '0.5', eventId: 'b'))->assertOk();

        $this->assertSame(2, BonvoiceCallEvent::query()->count());
        $this->assertDatabaseHas('bonvoice_call_events', ['call_id' => 'call-a-001', 'event_id' => 'a']);
        $this->assertDatabaseHas('bonvoice_call_events', ['call_id' => 'call-b-001', 'event_id' => 'b']);
    }

    public function test_1020_on_first_write_is_retried_and_processed(): void
    {
        Log::spy();

        $attempts = 0;
        $realStore = app(BonvoiceCallEventStore::class);
        $mock = Mockery::mock(BonvoiceCallEventStore::class);
        $mock->shouldReceive('upsertFromWebhook')
            ->andReturnUsing(function (array $payload, int $webhookLogId) use (&$attempts, $realStore): BonvoiceCallEvent {
                $attempts++;

                if ($attempts === 1) {
                    throw $this->recordChangedException();
                }

                return $realStore->upsertFromWebhook($payload, $webhookLogId);
            });
        $this->app->instance(BonvoiceCallEventStore::class, $mock);

        $log = $this->makeReceivedLog($this->lifecyclePayload('call-retry-1020-001', '1', eventId: 'e1'));
        $processor = $this->app->make(BonvoiceWebhookProcessorService::class);
        $result = $processor->process($log);

        $this->assertSame(2, $attempts);
        $this->assertSame(BonvoiceWebhookProcessorService::STATUS_PROCESSED, $result->processing_status);
        $this->assertNull($result->processing_error);
        $this->assertSame(1, BonvoiceCallEvent::query()->where('call_id', 'call-retry-1020-001')->count());
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($log): bool {
            return $message === '[BonVoice Webhook] Retrying call event persistence after DB contention.'
                && ($context['attempt'] ?? null) === 1
                && ($context['webhook_log_id'] ?? null) === $log->id;
        })->once();
    }

    public function test_exhausted_1020_retries_mark_log_failed_for_outbox_safety_net(): void
    {
        $mock = Mockery::mock(BonvoiceCallEventStore::class);
        $mock->shouldReceive('upsertFromWebhook')
            ->times(3)
            ->andThrow($this->recordChangedException());
        $this->app->instance(BonvoiceCallEventStore::class, $mock);

        $log = $this->makeReceivedLog($this->lifecyclePayload('call-retry-exhaust-001', '0.5', eventId: 'e05'));
        $processor = $this->app->make(BonvoiceWebhookProcessorService::class);

        try {
            $processor->process($log);
            $this->fail('Expected contention exception after retry exhaustion.');
        } catch (\Throwable $exception) {
            $this->assertTrue(BonvoiceCallEventWriteContention::isRetryable($exception));
        }

        $log->refresh();
        $this->assertSame(BonvoiceWebhookProcessorService::STATUS_FAILED, $log->processing_status);
        $this->assertNotNull($log->processing_error);
        $this->assertStringContainsString('1020', $log->processing_error);
        $this->assertSame(0, BonvoiceCallEvent::query()->where('call_id', 'call-retry-exhaust-001')->count());
    }

    public function test_non_contention_query_exception_is_not_retried(): void
    {
        $attempts = 0;
        $mock = Mockery::mock(BonvoiceCallEventStore::class);
        $mock->shouldReceive('upsertFromWebhook')
            ->andReturnUsing(function () use (&$attempts): never {
                $attempts++;
                throw $this->duplicateKeyException();
            });
        $this->app->instance(BonvoiceCallEventStore::class, $mock);

        $log = $this->makeReceivedLog($this->lifecyclePayload('call-no-retry-001', '0.5', eventId: 'e05'));
        $processor = $this->app->make(BonvoiceWebhookProcessorService::class);

        try {
            $processor->process($log);
            $this->fail('Expected QueryException.');
        } catch (QueryException) {
            // Expected — duplicate key is handled inside the store on real writes,
            // but a non-1020 bubbling from upsert must not consume retry attempts.
        }

        $this->assertSame(1, $attempts);
        $log->refresh();
        $this->assertSame(BonvoiceWebhookProcessorService::STATUS_FAILED, $log->processing_status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeReceivedLog(array $payload): BonvoiceWebhookLog
    {
        return BonvoiceWebhookLog::query()->create([
            'event_type' => ($payload['callType'] ?? '').(isset($payload['Status']) ? ':'.$payload['Status'] : ''),
            'payload' => $payload,
            'request_headers' => [],
            'received_at' => now(),
            'processing_status' => BonvoiceWebhookLog::STATUS_RECEIVED,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecyclePayload(string $callId, string $callType, ?string $status = null, string $eventId = 'evt'): array
    {
        $payload = [
            'SourceNumber' => '9876501111',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1800123456',
            'StartTime' => Carbon::parse('2026-09-01T10:57:00')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => $callType,
            'AccountID' => 'acct-001',
            'callID' => $callId,
            'Direction' => 'Inbound',
            'Network' => 'gsm',
            'eventID' => $eventId,
        ];

        if ($status !== null) {
            $payload['Status'] = $status;
        }

        return $payload;
    }

    private function recordChangedException(): QueryException
    {
        $previous = new \PDOException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'bonvoice_call_events\'; try restarting transaction');
        $previous->errorInfo = ['HY000', 1020, 'Record has changed since last read in table \'bonvoice_call_events\'; try restarting transaction'];

        return new QueryException(
            'mysql',
            'update `bonvoice_call_events` set `call_type` = ? where `call_id` = ? and `leg` = ?',
            ['1', 'call-retry-1020-001', 'call'],
            $previous,
        );
    }

    private function duplicateKeyException(): QueryException
    {
        $previous = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];

        return new QueryException(
            'mysql',
            'insert into `bonvoice_call_events`',
            [],
            $previous,
        );
    }
}
