<?php

namespace App\Support\Pos;

use InvalidArgumentException;

final class PosUpiUriBuilder
{
    public function build(string $vpa, string $payeeName, string $amount, string $tr): string
    {
        $vpa = trim($vpa);
        $payeeName = trim($payeeName);
        $tr = trim($tr);

        if ($vpa === '' || $payeeName === '' || $tr === '') {
            throw new InvalidArgumentException('UPI URI requires VPA, payee name, and transaction reference.');
        }

        if (! preg_match('/^\d+\.\d{2}$/', $amount)) {
            throw new InvalidArgumentException('UPI amount must be a 2-decimal rupee string.');
        }

        return 'upi://pay?'.http_build_query([
            'pa' => $vpa,
            'pn' => $payeeName,
            'am' => $amount,
            'tr' => $tr,
            'cu' => 'INR',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function formatAmount(float|string $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }
}
