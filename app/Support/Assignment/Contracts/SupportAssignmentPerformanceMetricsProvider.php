<?php

namespace App\Support\Assignment\Contracts\Performance;

use App\Models\User;

interface SupportAssignmentPerformanceMetricsProvider
{
    public function resolutionTime(): ResolutionTimeMetric;

    public function slaCompliance(): SlaComplianceMetric;

    public function csat(): CsatMetric;

    public function reopenRate(): ReopenRateMetric;

    public function firstResponse(): FirstResponseMetric;

    public function qualityScore(): QualityScoreMetric;

    /**
     * @return array<string, float|null>
     */
    public function snapshotFor(User $user): array;
}
