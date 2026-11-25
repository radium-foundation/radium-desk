<?php

namespace App\Services\Operations;

use App\Models\BonvoiceWebhookLog;
use App\ReadModels\Operations\RadiumBoxWatchdogSyncFailureReadModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class WatchdogHygieneMetricsService
{
    public function __construct(
        private readonly RadiumBoxWatchdogSyncFailureReadModel $radiumBoxReadModel,
        private readonly WatchdogDeadLetterClassifier $deadLetterClassifier,
        private readonly WatchdogBonvoiceWebhookFailureClassifier $bonvoiceClassifier,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $at = null): array
    {
        $at ??= now();
        $radiumBox = $this->radiumBoxReadModel->summarize($at);
        $deadLetter = $this->deadLetterClassifier->summarize($at);

        $bonvoice = [
            'legacy_failed_24h' => 0,
            'downgraded_no_channel_24h' => 0,
            'critical_failed_24h' => 0,
        ];

        if (Schema::hasTable('bonvoice_webhook_logs')) {
            $logs = BonvoiceWebhookLog::query()
                ->where('processing_status', BonvoiceWebhookLog::STATUS_FAILED)
                ->where('received_at', '>=', $at->copy()->subHours(24))
                ->get();
            $counts = $this->bonvoiceClassifier->countsForRecentFailures($logs);
            $bonvoice = [
                'legacy_failed_24h' => $counts['legacy_count'],
                'downgraded_no_channel_24h' => $counts['downgraded_count'],
                'critical_failed_24h' => $counts['critical_count'],
            ];
        }

        return [
            'radiumbox_failed_syncs' => [
                'legacy_total' => $radiumBox['legacy_failed_count'],
                'actionable' => $radiumBox['actionable_count'],
                'aging' => $radiumBox['aging_count'],
                'debt' => $radiumBox['debt_count'],
                'excluded_cfpay' => $radiumBox['excluded_prefix_count'],
            ],
            'queue_dead_letter' => [
                'legacy_total' => $deadLetter['legacy_total'],
                'actionable' => $deadLetter['actionable_count'],
                'recovered' => $deadLetter['recovered_count'],
                'historical' => $deadLetter['historical_count'],
                'duplicate' => $deadLetter['duplicate_count'],
                'unresolvable_recent' => $deadLetter['unresolvable_recent_count'],
                'by_job_class' => $deadLetter['by_job_class'],
            ],
            'bonvoice_webhook_failures_24h' => $bonvoice,
        ];
    }
}
