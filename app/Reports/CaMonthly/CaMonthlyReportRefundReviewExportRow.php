<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportRefundReviewExportRow
{
    /**
     * @param  list<string>  $cells
     */
    public function __construct(
        public readonly array $cells,
    ) {}
}
