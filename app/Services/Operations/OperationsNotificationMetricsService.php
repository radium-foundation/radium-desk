<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Schema;

class OperationsNotificationMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(?OperationsAuditAggregator $auditAggregator = null): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return $this->emptyMetrics();
        }

        return ($auditAggregator ?? new OperationsAuditAggregator(collect()))->metrics();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMetrics(): array
    {
        return [
            'sent_today' => 0,
            'failed_today' => 0,
            'skipped_today' => 0,
            'channel_totals' => [],
            'success_rate' => null,
            'average_delivery_ms' => null,
        ];
    }
}
