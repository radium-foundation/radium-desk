<?php

namespace App\Services\Finance;

use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use Illuminate\Http\Request;

final class CaMonthlyReportExportThreshold
{
    public function __construct(
        private readonly CaMonthlyStatutoryLineReadModel $readModel,
    ) {}

    public function requiresAsync(Request $request): bool
    {
        return $this->readModel->countExportLines($request) > (int) config('ca_monthly_report.sync_max_lines', 500);
    }

    public function estimatedLineCount(Request $request): int
    {
        return $this->readModel->countExportLines($request);
    }
}
