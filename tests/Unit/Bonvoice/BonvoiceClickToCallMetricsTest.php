<?php

namespace Tests\Unit\Bonvoice;

use App\Enums\BonvoiceClickToCallFailureCode;
use App\Services\Bonvoice\BonvoiceClickToCallMetrics;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BonvoiceClickToCallMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_today_summary_tracks_success_and_failure_codes(): void
    {
        $metrics = app(BonvoiceClickToCallMetrics::class);

        $metrics->recordSuccess('EVENTSUCCESS0001');
        $metrics->recordFailure(BonvoiceClickToCallFailureCode::Connection, eventId: 'EVENTFAIL0000001');
        $metrics->recordFailure(BonvoiceClickToCallFailureCode::Connection, eventId: 'EVENTFAIL0000002');
        $metrics->recordFailure(BonvoiceClickToCallFailureCode::Auth, correlationId: 'CORRAUTH00000001');

        $summary = $metrics->todaySummary();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['success']);
        $this->assertSame(3, $summary['failure']);
        $this->assertSame(2, $summary['by_failure_code']['connection']);
        $this->assertSame(1, $summary['by_failure_code']['auth']);
    }
}
