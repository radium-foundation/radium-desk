<?php

namespace App\Services\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageChannelReport;
use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;

final class StatutoryCoverageWatchdogAssessment
{
    /**
     * @param  list<StatutoryCoverageChannelReport>  $channelReports
     * @return list<string>
     */
    public function suggestAlertKeys(array $channelReports): array
    {
        $prefix = (string) config('statutory_coverage.watchdog.alert_key_prefix', 'statutory_coverage');
        $keys = [];

        foreach ($channelReports as $report) {
            $missingCommerce = (int) ($report->counts[StatutoryCoverageClassification::MissingCommerce->value] ?? 0);
            $eligibleMissing = (int) ($report->counts[StatutoryCoverageClassification::EligibleMissingInvoice->value] ?? 0);
            $conflicts = (int) ($report->counts[StatutoryCoverageClassification::UnmatchedConflict->value] ?? 0);
            $partial = ! $report->reportComplete;

            if ($missingCommerce > 0) {
                $keys[] = $prefix.':missing_commerce:'.$report->channel;
            }
            if ($eligibleMissing > 0) {
                $keys[] = $prefix.':eligible_missing_invoice:'.$report->channel;
            }
            if ($conflicts > 0) {
                $keys[] = $prefix.':conflict:'.$report->channel;
            }
            if ($partial) {
                $keys[] = $prefix.':partial_report:'.$report->channel;
            }
        }

        return array_values(array_unique($keys));
    }
}
