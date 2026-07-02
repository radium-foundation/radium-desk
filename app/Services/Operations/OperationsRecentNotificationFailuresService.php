<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\Notifications\NotificationAuditTrailService;
use Illuminate\Support\Facades\Schema;

class OperationsRecentNotificationFailuresService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 15): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $logs = AuditLog::query()
            ->with(['auditable' => function ($morphTo): void {
                $morphTo->morphWith([
                    Incident::class => ['order'],
                ]);
            }])
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('created_at')
            ->limit(100)
            ->get();

        $failures = [];

        foreach ($logs as $log) {
            foreach ($this->failedChannels($log) as $failure) {
                $incident = $log->auditable instanceof Incident ? $log->auditable : null;

                $failures[] = [
                    'timestamp' => $log->created_at,
                    'channel' => $failure['channel_label'],
                    'incident_reference' => $incident?->display_reference,
                    'incident_url' => $incident !== null ? route('incidents.show', $incident) : null,
                    'customer_name' => $incident?->order?->customer_name,
                    'reason' => $failure['reason'],
                    'notification_type' => $log->new_values['notification_type'] ?? null,
                ];
            }
        }

        return array_slice($failures, 0, $limit);
    }

    /**
     * @return list<array{channel_label: string, reason: string}>
     */
    private function failedChannels(AuditLog $log): array
    {
        $channelResults = $log->new_values['channel_results'] ?? [];

        if (! is_array($channelResults)) {
            return [];
        }

        $failures = [];

        foreach ($channelResults as $result) {
            if (! is_array($result)) {
                continue;
            }

            $success = (bool) ($result['success'] ?? false);
            $status = (string) ($result['status'] ?? '');

            if ($success || $status === 'not_yet_configured') {
                continue;
            }

            $channel = (string) ($result['channel'] ?? 'unknown');

            $failures[] = [
                'channel_label' => $this->channelLabel($channel),
                'reason' => trim((string) ($result['message'] ?? 'Delivery failed.')) ?: 'Delivery failed.',
            ];
        }

        return $failures;
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'desktop' => 'Desktop',
            'telegram' => 'Telegram',
            default => ucfirst($channel),
        };
    }
}
