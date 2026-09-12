<?php

namespace Tests\Unit\HistoricalSearch;

use App\Services\HistoricalSearch\HistoricalSearchCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HistoricalSearchCircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'historical_search.enabled' => true,
            'historical_search.circuit_breaker.failure_threshold' => 2,
            'historical_search.circuit_breaker.open_seconds' => 30,
            'historical_search.circuit_breaker.window_seconds' => 60,
        ]);
    }

    public function test_opens_after_failure_threshold(): void
    {
        $breaker = app(HistoricalSearchCircuitBreaker::class);

        $breaker->recordFailure();
        $this->assertFalse($breaker->isOpen());

        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());
        $this->assertSame('degraded', $breaker->snapshot()['status']);
    }

    public function test_success_resets_circuit(): void
    {
        $breaker = app(HistoricalSearchCircuitBreaker::class);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());

        $breaker->recordSuccess();
        $this->assertFalse($breaker->isOpen());
    }
}
