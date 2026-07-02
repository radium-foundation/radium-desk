<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\Notifications\NotificationAuditTrailService;
use Illuminate\Support\Facades\Schema;

class OperationsNotificationMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return $this->emptyMetrics();
        }

        $logs = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->where('created_at', '>=', today())
            ->get();

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $durations = [];
        $channelTotals = [
            'whatsapp' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
            'email' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
            'desktop' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
            'telegram' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
        ];

        foreach ($logs as $log) {
            $aggregateSuccess = (bool) ($log->new_values['aggregate_success'] ?? false);
            $channelResults = $log->new_values['channel_results'] ?? [];

            if (! is_array($channelResults)) {
                continue;
            }

            $hadSent = false;
            $hadFailed = false;
            $hadSkipped = false;

            foreach ($channelResults as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $channel = (string) ($result['channel'] ?? 'unknown');
                $status = (string) ($result['status'] ?? '');
                $success = (bool) ($result['success'] ?? false);

                if (! isset($channelTotals[$channel])) {
                    $channelTotals[$channel] = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
                }

                if ($status === 'not_yet_configured' || $this->isSkippedResult($result)) {
                    $channelTotals[$channel]['skipped']++;
                    $hadSkipped = true;

                    continue;
                }

                if ($success) {
                    $channelTotals[$channel]['sent']++;
                    $hadSent = true;
                } else {
                    $channelTotals[$channel]['failed']++;
                    $hadFailed = true;
                }

                $durationMs = (int) ($result['duration_ms'] ?? 0);

                if ($durationMs > 0) {
                    $durations[] = $durationMs;
                }
            }

            if ($hadFailed && ! $aggregateSuccess) {
                $failed++;
            } elseif ($hadSent) {
                $sent++;
            } elseif ($hadSkipped) {
                $skipped++;
            }
        }

        $attempted = $sent + $failed;
        $successRate = $attempted > 0 ? round(($sent / $attempted) * 100, 1) : null;
        $averageDeliveryMs = $durations !== [] ? (int) round(array_sum($durations) / count($durations)) : null;

        return [
            'sent_today' => $sent,
            'failed_today' => $failed,
            'skipped_today' => $skipped,
            'channel_totals' => $channelTotals,
            'success_rate' => $successRate,
            'average_delivery_ms' => $averageDeliveryMs,
        ];
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function isSkippedResult(array $result): bool
    {
        $message = strtolower(trim((string) ($result['message'] ?? '')));

        return str_contains($message, 'not configured') || str_contains($message, 'disabled');
    }
}
