<?php

namespace Tests\Unit\Operations;

use App\Enums\BonvoiceClickToCallFailureCode;
use App\Models\BonvoiceCallEvent;
use App\Services\Bonvoice\BonvoiceClickToCallMetrics;
use App\Services\Operations\ProductionEveningHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductionEveningHealthBonvoiceSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-08 20:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bonvoice_summary_counts_production_direction_and_status_values(): void
    {
        $this->seedCall('call-inbound-answered', 'Inbound', 'ANSWERED');
        $this->seedCall('call-inbound-missed', 'Inbound', 'NOANSWER');
        $this->seedCall('call-inbound-lowercase', 'inbound', 'FAILED');
        $this->seedCall('call-outbound', 'Outbound', 'ANSWERED');
        $this->seedCall('call-yesterday', 'Inbound', 'NOANSWER', now()->subDay());

        $summary = app(ProductionEveningHealthService::class)->build()['bonvoice_calls'];

        $this->assertSame(4, $summary['total']);
        $this->assertSame(3, $summary['inbound']);
        $this->assertSame(1, $summary['outbound']);
        $this->assertSame(2, $summary['missed']);
    }

    public function test_bonvoice_summary_classifies_all_supported_direction_and_status_variants(): void
    {
        $this->seedCall('dir-inbound', 'Inbound', 'ANSWERED');
        $this->seedCall('dir-inbound-lower', 'inbound', 'COMPLETED');
        $this->seedCall('dir-incoming', 'incoming', 'NOINPUT');
        $this->seedCall('dir-outbound', 'Outbound', 'ANSWERED');
        $this->seedCall('missed-noanswer', 'Inbound', 'NOANSWER');
        $this->seedCall('missed-noinput', 'Inbound', 'NOINPUT');
        $this->seedCall('missed-failed', 'Inbound', 'FAILED');

        $summary = app(ProductionEveningHealthService::class)->build()['bonvoice_calls'];

        $this->assertSame(7, $summary['total']);
        $this->assertSame(6, $summary['inbound']);
        $this->assertSame(1, $summary['outbound']);
        $this->assertSame(4, $summary['missed']);
    }

    public function test_bonvoice_summary_does_not_count_answered_inbound_as_missed(): void
    {
        $this->seedCall('answered-inbound', 'Inbound', 'ANSWERED');
        $this->seedCall('completed-inbound', 'Inbound', 'COMPLETED');

        $summary = app(ProductionEveningHealthService::class)->build()['bonvoice_calls'];

        $this->assertSame(2, $summary['total']);
        $this->assertSame(2, $summary['inbound']);
        $this->assertSame(0, $summary['outbound']);
        $this->assertSame(0, $summary['missed']);
    }

    public function test_bonvoice_summary_treats_legacy_lowercase_direction_labels(): void
    {
        $this->seedCall('call-incoming', 'incoming', 'NOINPUT');
        $this->seedCall('call-outgoing', 'outgoing', 'COMPLETED');

        $summary = app(ProductionEveningHealthService::class)->build()['bonvoice_calls'];

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['inbound']);
        $this->assertSame(1, $summary['outbound']);
        $this->assertSame(1, $summary['missed']);
    }

    public function test_evening_health_includes_click_to_call_metrics(): void
    {
        $metrics = app(BonvoiceClickToCallMetrics::class);
        $metrics->recordSuccess('EVENTSUCCESS0001');
        $metrics->recordFailure(BonvoiceClickToCallFailureCode::ProviderResponse, eventId: 'EVENTFAIL0000001');

        $summary = app(ProductionEveningHealthService::class)->build()['bonvoice_click_to_call'];

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['success']);
        $this->assertSame(1, $summary['failure']);
        $this->assertSame(1, $summary['by_failure_code']['provider_response']);
    }

    private function seedCall(
        string $callId,
        string $direction,
        string $status,
        ?Carbon $startedAt = null,
    ): void {
        BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'call',
            'direction' => $direction,
            'status' => $status,
            'started_at' => $startedAt ?? now(),
            'payload' => [
                'callID' => $callId,
                'Direction' => $direction,
                'Status' => $status,
            ],
        ]);
    }
}
