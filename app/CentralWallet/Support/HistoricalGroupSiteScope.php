<?php

namespace App\CentralWallet\Support;

final class HistoricalGroupSiteScope
{
    /** @var list<string> */
    public const GROUP_SITES = [
        'radiumbox.com',
        'rdservice.in',
        'rdservice.net',
    ];

    public static function inferOriginFromOrderNumber(string $orderNumber): string
    {
        $upper = strtoupper(trim($orderNumber));

        if (str_starts_with($upper, 'RD')) {
            return 'rdservice.in';
        }

        if (str_starts_with($upper, 'RB')) {
            return 'radiumbox.com';
        }

        if (str_starts_with($upper, 'RN') || str_starts_with($upper, 'RA') || str_starts_with($upper, 'RNP')) {
            return 'rdservice.net';
        }

        return 'unknown';
    }
}
