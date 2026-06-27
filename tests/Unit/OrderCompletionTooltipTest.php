<?php

namespace Tests\Unit;

use App\Enums\OrderCompletionStatus;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderCompletionTooltipTest extends TestCase
{
    public function test_format_duration_between_hours_and_minutes(): void
    {
        $from = Carbon::parse('2026-06-24 08:23:00');
        $to = Carbon::parse('2026-06-24 14:35:00');

        $this->assertSame(
            '6 hours 12 minutes',
            Order::formatDurationBetween($from, $to),
        );
    }

    public function test_format_duration_between_days_and_hours(): void
    {
        $from = Carbon::parse('2026-06-24 10:45:00');
        $to = Carbon::parse('2026-06-25 13:45:00');

        $this->assertSame(
            '1 day 3 hours',
            Order::formatDurationBetween($from, $to),
        );
    }

    public function test_format_compact_duration_between_hours_and_minutes(): void
    {
        $from = Carbon::parse('2026-06-26 06:46:00');
        $to = Carbon::parse('2026-06-26 22:15:00');

        $this->assertSame(
            '15h 29m',
            Order::formatCompactDurationBetween($from, $to),
        );
    }

    public function test_format_compact_duration_between_days_and_hours(): void
    {
        $from = Carbon::parse('2026-06-24 10:45:00');
        $to = Carbon::parse('2026-06-26 14:45:00');

        $this->assertSame(
            '2d 4h',
            Order::formatCompactDurationBetween($from, $to),
        );
    }

    public function test_format_compact_duration_between_minutes_only(): void
    {
        $from = Carbon::parse('2026-06-26 12:00:00');
        $to = Carbon::parse('2026-06-26 12:18:00');

        $this->assertSame(
            '18m',
            Order::formatCompactDurationBetween($from, $to),
        );
    }

    public function test_pending_admin_tooltip_includes_created_and_pending_duration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 20:47:00'));

        $order = new Order([
            'transaction_id' => null,
        ]);

        $loggedAt = Carbon::parse('2026-06-24 14:35:00');
        $html = $order->completionTooltipHtml($loggedAt);

        $this->assertStringContainsString('Waiting for Transaction ID', $html);
        $this->assertStringContainsString('Created:', $html);
        $this->assertStringContainsString('24 Jun 2026, 02:35 PM', $html);
        $this->assertStringContainsString('Pending for:', $html);
        $this->assertStringContainsString('6 hours 12 minutes', $html);
        $this->assertSame(OrderCompletionStatus::PendingAdmin, $order->completionStatus());

        Carbon::setTestNow();
    }

    public function test_completed_tooltip_includes_transaction_and_turnaround(): void
    {
        $order = new Order([
            'transaction_id' => 'TX123456',
            'completed_at' => Carbon::parse('2026-06-25 10:45:00'),
        ]);

        $loggedAt = Carbon::parse('2026-06-24 07:45:00');
        $html = $order->completionTooltipHtml($loggedAt);

        $this->assertStringContainsString('Transaction ID: TX123456', $html);
        $this->assertStringContainsString('Completed:', $html);
        $this->assertStringContainsString('25 Jun 2026, 10:45 AM', $html);
        $this->assertStringContainsString('Total turnaround:', $html);
        $this->assertStringContainsString('1 day 3 hours', $html);
        $this->assertSame(OrderCompletionStatus::Completed, $order->completionStatus());
    }
}
