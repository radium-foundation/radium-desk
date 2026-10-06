<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportPaymentStatusDisplay
{
    public static function fromPaymentChannel(string $paymentChannel): string
    {
        return self::fromPaymentState(false, false, $paymentChannel);
    }

    public static function fromPaymentState(bool $unpaid, bool $partial, string $paymentChannel): string
    {
        if ($unpaid || $paymentChannel === CaMonthlyReportPaymentChannelResolver::CHANNEL_UNPAID) {
            return 'Unpaid';
        }

        if ($partial || $paymentChannel === CaMonthlyReportPaymentChannelResolver::CHANNEL_PARTIAL_PAID) {
            return 'Partial Paid';
        }

        return $paymentChannel !== '' ? 'Paid' : 'Unknown';
    }
}
