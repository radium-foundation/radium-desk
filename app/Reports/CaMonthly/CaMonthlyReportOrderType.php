<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportOrderType
{
    public const HARDWARE = 'Goods';

    public const SERVICE = 'Service';

    public const BUNDLED = 'Bundled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::HARDWARE,
            self::SERVICE,
            self::BUNDLED,
        ];
    }
}
