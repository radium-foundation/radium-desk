<?php

namespace App\Services\Operations;

use App\Enums\OperationsHealthStatus;
use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Models\AuditLog;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\SystemSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class OperationsIntegrationHealthService
{
    public function __construct(
        private readonly CashfreeIntegrationHealthProbe $cashfreeProbe,
        private readonly SystemSettingsService $systemSettings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(?OperationsDashboardSnapshot $snapshot = null): array
    {
        return [
            $this->cashfreeCard($snapshot),
            $this->interaktCard($snapshot),
            $this->zeptomailCard($snapshot),
            $this->telegramCard($snapshot),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashfreeCard(?OperationsDashboardSnapshot $snapshot): array
    {
        $probeSnapshot = $snapshot?->cashfreeIntegrationSnapshot()
            ?? $this->cashfreeProbe->probe();
        $status = $this->mapConnectionStatus($probeSnapshot->connectionStatus);

        return [
            'key' => 'cashfree',
            'label' => 'Cashfree',
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'last_success_at' => $probeSnapshot->lastSuccessAt,
            'last_failure_at' => $probeSnapshot->lastFailureAt,
            'retry_count' => $probeSnapshot->retryCount,
            'detail' => $probeSnapshot->lastErrorMessage ?? 'Payment webhook integration.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function interaktCard(?OperationsDashboardSnapshot $snapshot): array
    {
        if (! filled(Config::get('interakt.api_key'))) {
            return $this->integrationCard('interakt', 'Interakt', OperationsHealthStatus::NotConfigured, 'Interakt API key is not configured.');
        }

        if (! $this->systemSettings->getBool('whatsapp.api_enabled', false)) {
            return $this->integrationCard('interakt', 'Interakt', OperationsHealthStatus::Disabled, 'WhatsApp API integration is disabled.');
        }

        if (! Schema::hasTable('interakt_messages')) {
            return $this->integrationCard('interakt', 'Interakt', OperationsHealthStatus::Warning, 'Message store unavailable.');
        }

        $interaktInputs = $snapshot?->interaktInputs() ?? [
            'last_success_at' => null,
            'recent_failures' => 0,
            'has_recent_activity' => false,
        ];

        $lastSuccess = $interaktInputs['last_success_at'];
        $recentFailures = (int) $interaktInputs['recent_failures'];

        if ($recentFailures > 0) {
            return $this->integrationCard(
                'interakt',
                'Interakt',
                OperationsHealthStatus::Warning,
                "{$recentFailures} outbound failure(s) in the last 24 hours.",
                $lastSuccess,
            );
        }

        if ($lastSuccess === null) {
            return $this->integrationCard('interakt', 'Interakt', OperationsHealthStatus::Healthy, 'Configured with no outbound messages yet.');
        }

        return $this->integrationCard('interakt', 'Interakt', OperationsHealthStatus::Healthy, 'WhatsApp messaging is operational.', $lastSuccess);
    }

    /**
     * @return array<string, mixed>
     */
    private function zeptomailCard(?OperationsDashboardSnapshot $snapshot): array
    {
        if (! $this->systemSettings->getBool('notifications.email.enabled', false)) {
            return $this->integrationCard('zeptomail', 'ZeptoMail', OperationsHealthStatus::Disabled, 'Email notifications are disabled.');
        }

        if (! $this->systemSettings->getBool('email.api_enabled', false)) {
            return $this->integrationCard('zeptomail', 'ZeptoMail', OperationsHealthStatus::Disabled, 'Email API integration is disabled.');
        }

        if (! (bool) config('mail.enabled') || config('mail.default') === 'log') {
            return $this->integrationCard('zeptomail', 'ZeptoMail', OperationsHealthStatus::NotConfigured, 'Mail transport is not configured for production delivery.');
        }

        $emailMetrics = $this->channelAuditSummary('email', $snapshot);

        if ($emailMetrics['failed'] > 0) {
            return $this->integrationCard(
                'zeptomail',
                'ZeptoMail',
                OperationsHealthStatus::Warning,
                "{$emailMetrics['failed']} email failure(s) today.",
                $emailMetrics['last_success_at'],
            );
        }

        return $this->integrationCard(
            'zeptomail',
            'ZeptoMail',
            OperationsHealthStatus::Healthy,
            "{$emailMetrics['sent']} email(s) sent today.",
            $emailMetrics['last_success_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramCard(?OperationsDashboardSnapshot $snapshot): array
    {
        if (! $this->systemSettings->getBool('notifications.telegram.enabled', false)) {
            return $this->integrationCard('telegram', 'Telegram', OperationsHealthStatus::Disabled, 'Telegram notifications are disabled.');
        }

        if (! $this->systemSettings->getBool('telegram.api_enabled', false)) {
            return $this->integrationCard('telegram', 'Telegram', OperationsHealthStatus::NotConfigured, 'Telegram API is not enabled.');
        }

        $telegramMetrics = $this->channelAuditSummary('telegram', $snapshot);

        if ($telegramMetrics['sent'] === 0 && $telegramMetrics['failed'] === 0) {
            return $this->integrationCard('telegram', 'Telegram', OperationsHealthStatus::NotConfigured, 'Enabled but channel is not yet wired for delivery.');
        }

        if ($telegramMetrics['failed'] > 0) {
            return $this->integrationCard(
                'telegram',
                'Telegram',
                OperationsHealthStatus::Warning,
                "{$telegramMetrics['failed']} Telegram failure(s) today.",
                $telegramMetrics['last_success_at'],
            );
        }

        return $this->integrationCard(
            'telegram',
            'Telegram',
            OperationsHealthStatus::Healthy,
            "{$telegramMetrics['sent']} Telegram message(s) sent today.",
            $telegramMetrics['last_success_at'],
        );
    }

    /**
     * @return array{sent: int, failed: int, last_success_at: ?Carbon}
     */
    private function channelAuditSummary(string $channel, ?OperationsDashboardSnapshot $snapshot): array
    {
        if ($snapshot !== null) {
            return $snapshot->auditAggregator()->channelSummary($channel);
        }

        if (! Schema::hasTable('audit_logs')) {
            return ['sent' => 0, 'failed' => 0, 'last_success_at' => null];
        }

        $logs = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->where('created_at', '>=', today())
            ->latest('created_at')
            ->get();

        return (new OperationsAuditAggregator($logs))->channelSummary($channel);
    }

    private function mapConnectionStatus(string $connectionStatus): OperationsHealthStatus
    {
        return match ($connectionStatus) {
            'healthy' => OperationsHealthStatus::Healthy,
            'degraded' => OperationsHealthStatus::Warning,
            'idle' => OperationsHealthStatus::Warning,
            'not_configured' => OperationsHealthStatus::NotConfigured,
            default => OperationsHealthStatus::Failed,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationCard(
        string $key,
        string $label,
        OperationsHealthStatus $status,
        string $detail,
        mixed $lastSuccessAt = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'last_success_at' => $lastSuccessAt instanceof Carbon ? $lastSuccessAt : null,
            'detail' => $detail,
        ];
    }
}
