<?php

namespace Tests\Unit\Operations;

use App\Models\AuditLog;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Operations\OperationsAuditAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OperationsAuditAggregatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_and_channel_summary_share_single_log_scan(): void
    {
        $log = new AuditLog([
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
            'new_values' => [
                'aggregate_success' => false,
                'channel_results' => [
                    [
                        'channel' => 'email',
                        'status' => 'failed',
                        'success' => false,
                        'message' => 'SMTP timeout',
                        'duration_ms' => 800,
                    ],
                    [
                        'channel' => 'whatsapp',
                        'status' => 'sent',
                        'success' => true,
                        'duration_ms' => 400,
                    ],
                ],
            ],
            'created_at' => now(),
        ]);

        $aggregator = new OperationsAuditAggregator(collect([$log]));

        $metrics = $aggregator->metrics();
        $emailSummary = $aggregator->channelSummary('email');

        $this->assertSame(1, $metrics['failed_today']);
        $this->assertSame(0, $metrics['sent_today']);
        $this->assertSame(600, $metrics['average_delivery_ms']);
        $this->assertSame(1, $emailSummary['failed']);
        $this->assertSame(1, $aggregator->channelFailuresToday('email'));
        $this->assertSame(1, $aggregator->dispatchesWithChannelFailuresCount());
        $this->assertTrue($aggregator->dispatchHadChannelFailure($log));
    }

    public function test_empty_logs_return_empty_metrics(): void
    {
        $aggregator = new OperationsAuditAggregator(new Collection);

        $this->assertSame([
            'sent_today' => 0,
            'failed_today' => 0,
            'skipped_today' => 0,
            'channel_totals' => [],
            'success_rate' => null,
            'average_delivery_ms' => null,
        ], $aggregator->metrics());
    }
}
