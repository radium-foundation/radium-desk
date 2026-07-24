<?php

namespace Tests\Unit\Bonvoice;

use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceIncomingCallLatency;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BonvoiceIncomingCallLatencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['bonvoice.incoming_latency_log' => true]);
        app(BonvoiceIncomingCallLatency::class)->reset();
    }

    public function test_measure_logs_stage_with_duration_and_total(): void
    {
        Log::spy();

        $latency = app(BonvoiceIncomingCallLatency::class);
        $latency->begin(42);
        $latency->setCallId('CALL-LAT-001');

        $result = $latency->measure(
            BonvoiceIncomingCallLatency::STAGE_RESOLVE,
            function (): string {
                usleep(1000);

                return 'ok';
            },
            ['recipient_user_id' => 7],
        );

        $this->assertSame('ok', $result);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[BonVoice Incoming Latency]'
                    && ($context['stage'] ?? null) === BonvoiceIncomingCallLatency::STAGE_RESOLVE
                    && ($context['webhook_log_id'] ?? null) === 42
                    && ($context['call_id'] ?? null) === 'CALL-LAT-001'
                    && ($context['recipient_user_id'] ?? null) === 7
                    && isset($context['duration_ms'], $context['total_ms'])
                    && $context['duration_ms'] >= 0
                    && $context['total_ms'] >= 0;
            })
            ->once();
    }

    public function test_disabled_flag_skips_logging_and_still_runs_callback(): void
    {
        config(['bonvoice.incoming_latency_log' => false]);
        Log::spy();

        $latency = app(BonvoiceIncomingCallLatency::class);
        $latency->begin(99);

        $result = $latency->measure(
            BonvoiceIncomingCallLatency::STAGE_PROCESS,
            fn (): int => 5,
        );

        $this->assertSame(5, $result);
        Log::shouldNotHaveReceived('info');
    }

    public function test_begin_from_webhook_log_uses_received_at_as_t0(): void
    {
        Log::spy();

        $webhookLog = new BonvoiceWebhookLog([
            'received_at' => now()->subMilliseconds(250),
        ]);
        $webhookLog->id = 55;

        $latency = app(BonvoiceIncomingCallLatency::class);
        $latency->beginFromWebhookLog($webhookLog);
        $latency->mark(BonvoiceIncomingCallLatency::STAGE_OUTBOX, null, [
            'outbox_ahead_count' => 0,
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[BonVoice Incoming Latency]'
                    && ($context['stage'] ?? null) === BonvoiceIncomingCallLatency::STAGE_OUTBOX
                    && ($context['webhook_log_id'] ?? null) === 55
                    && ($context['outbox_ahead_count'] ?? null) === 0
                    && ($context['total_ms'] ?? 0) >= 200;
            })
            ->once();
    }

    public function test_logging_failure_does_not_throw(): void
    {
        Log::shouldReceive('info')->andThrow(new \RuntimeException('log sink down'));

        $latency = app(BonvoiceIncomingCallLatency::class);
        $latency->begin(1);

        $latency->mark(BonvoiceIncomingCallLatency::STAGE_BROADCAST);

        $this->assertTrue(true);
    }
}
