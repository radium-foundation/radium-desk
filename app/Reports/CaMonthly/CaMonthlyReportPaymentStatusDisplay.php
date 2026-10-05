<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportPaymentStatusDisplay
{
    public static function fromPaymentChannel(string $paymentChannel): string
    {
        return match ($paymentChannel) {
            CaMonthlyReportPaymentChannelResolver::CHANNEL_UNPAID => 'Unpaid',
            CaMonthlyReportPaymentChannelResolver::CHANNEL_PARTIAL_PAID => 'Partial Paid',
            default => $paymentChannel !== '' ? 'Paid' : 'Unknown',
        };
    }
}
