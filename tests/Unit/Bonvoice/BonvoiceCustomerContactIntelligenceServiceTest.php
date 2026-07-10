<?php

namespace Tests\Unit\Bonvoice;

use App\Models\BonvoiceCallEvent;
use App\Services\Bonvoice\BonvoiceCustomerContactIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BonvoiceCustomerContactIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_today_contact_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 15:50:00', 'Asia/Kolkata'));

        $this->createCall('call-001', 'NOANSWER', Carbon::parse('2026-07-09 10:00:00'));
        $this->createCall('call-002', 'NOANSWER', Carbon::parse('2026-07-09 11:15:00'));
        $this->createCall('call-003', 'Answered', Carbon::parse('2026-07-09 14:00:00'));
        $this->createCall('call-004', 'Answered', Carbon::parse('2026-07-09 15:45:00'));

        $summary = app(BonvoiceCustomerContactIntelligenceService::class)->forCustomerPhone('9876543210', true);

        $this->assertNotNull($summary);
        $this->assertSame(4, $summary->totalToday);
        $this->assertSame(2, $summary->missedToday);
        $this->assertSame(2, $summary->answeredToday);
        $this->assertSame('Customer contacted 4 times today: 2 missed, 2 answered. Last call 15:45.', $summary->summaryLine);
        $this->assertTrue($summary->highUrgency);

        Carbon::setTestNow();
    }

    private function createCall(string $callId, string $status, Carbon $startedAt): void
    {
        BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'A',
            'customer_phone' => '9876543210',
            'direction' => 'Inbound',
            'status' => $status,
            'started_at' => $startedAt,
        ]);
    }
}
