<?php

namespace Tests\Unit\Cashfree;

use App\Services\Cashfree\CashfreeWebhookStageTiming;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CashfreeWebhookStageTimingUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_attempt_emits_bounded_category_without_sql_text(): void
    {
        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$captured): void {
            if ($event->message === CashfreeWebhookStageTiming::LOG_EVENT) {
                $captured[] = $event->context;
            }
        });

        $timing = $this->app->make(CashfreeWebhookStageTiming::class);
        $timing->activate();
        $timing->associateWebhookLogId(42);
        $timing->recordPersistRetryAttempt(1, 'db_contention');

        $this->assertCount(2, $captured);
        $retry = collect($captured)->firstWhere('stage', 'persist_attempt');
        $this->assertIsArray($retry);
        $this->assertSame('retry', $retry['outcome']);
        $this->assertSame(1, $retry['retry_attempt']);
        $this->assertSame('db_contention', $retry['error_category']);
        $this->assertArrayNotHasKey('message', $retry);
    }

    public function test_classify_query_exception_without_message_leakage(): void
    {
        $pdoException = new \PDOException('SQLSTATE[40001]: 1213 Deadlock', 1213);
        $exception = new QueryException(
            'mysql',
            'update `orders` set secret = ?',
            [],
            $pdoException,
        );

        $this->assertSame('db_contention', CashfreeWebhookStageTiming::classifyQueryException($exception));
    }
}
