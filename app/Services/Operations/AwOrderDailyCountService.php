<?php

namespace App\Services\Operations;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Counts AW- service-reference orders for one IST calendar day (half-open interval).
 */
final class AwOrderDailyCountService
{
    public function reportingTimezone(): string
    {
        return (string) config(
            'operations_aw_orders.daily_telegram.report_timezone',
            config('app.timezone', 'Asia/Kolkata'),
        );
    }

    public function previousReportDate(?Carbon $asOf = null): Carbon
    {
        $tz = $this->reportingTimezone();
        $anchor = ($asOf ?? now())->copy()->timezone($tz);

        return $anchor->copy()->subDay()->startOfDay();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function halfOpenBoundsForReportDate(Carbon $reportDateIst): array
    {
        $tz = $this->reportingTimezone();
        $start = $reportDateIst->copy()->timezone($tz)->startOfDay();
        $end = $start->copy()->addDay();

        return [$start, $end];
    }

    public function countForReportDate(Carbon $reportDateIst): int
    {
        [$start, $end] = $this->halfOpenBoundsForReportDate($reportDateIst);

        // Half-open [start, end) in the reporting timezone. Production MariaDB stores
        // application wall-clock timestamps (see P-24-22); compare formatted local bounds.
        return Order::query()
            ->whereNull('deleted_at')
            ->where('transaction_id', 'like', 'AW-%')
            ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $end->format('Y-m-d H:i:s'))
            ->count();
    }

    public function formatSummaryMessage(Carbon $reportDateIst, int $count): string
    {
        $tz = $this->reportingTimezone();
        $dateLabel = $reportDateIst->copy()->timezone($tz)->toDateString();

        return implode("\n", [
            'AW- order summary (Service Reference)',
            "Report date: {$dateLabel} ({$tz})",
            "Orders created: {$count}",
        ]);
    }
}
