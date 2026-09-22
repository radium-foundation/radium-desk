<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportWorkbookMeta
{
    public function __construct(
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $generatedAt,
    ) {}
}
