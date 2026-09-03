<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Support\Carbon;

final class StatutoryInvoiceScope
{
    public const STARTS_AT = '2026-09-01 00:00:00';

    public static function startsAt(): Carbon
    {
        $raw = config('statutory_invoices.invoice_scope_starts_at', self::STARTS_AT);
        $raw = is_string($raw) && trim($raw) !== '' ? trim($raw) : self::STARTS_AT;

        return Carbon::parse($raw, config('app.timezone'))->startOfSecond();
    }

    public static function contains(?Carbon $commercialAt): bool
    {
        if ($commercialAt === null) {
            return false;
        }

        return $commercialAt->gte(self::startsAt());
    }
}
