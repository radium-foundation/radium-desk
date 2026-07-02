<?php

namespace App\Services\Operations;

use App\Enums\InteraktMessageDirection;
use App\Enums\OperationsHealthStatus;
use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Models\AuditLog;
use App\Models\InteraktMessage;
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
    public function cards(): array
    {
        return [
            $this->cashfreeCard(),
            $this->interaktCard(),
            $this->zeptomailCard(),
            $this->telegramCard(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashfreeCard(): array
    {
        $snapshot = $this->cashfreeProbe->probe();
        $status = $this->mapConnectionStatus($snapshot->connectionStatus);

        return [
            'key' => 'cashfree',
            'label' => 'Cashfree',
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'last_success_at' => $snapshot->lastSuccessAt,
            'last_failure_at' => $snapshot->lastFailureAt,
            'retry_count' => $snapshot->retryCount,
            'detail' => $snapshot->lastErrorMessage ?? 'Payment webhook integration.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function interaktCard(): array
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

        $lastSuccess = InteraktMessage::query()
            ->where('direction', InteraktMessageDirection::Outgoing)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->value('sent_at');

        $recentFailures = (int) InteraktMessage::query()
            ->where('direction', InteraktMessageDirection::Outgoing)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('channel_failure_reason')
            ->count();

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
    private function zeptomailCard(): array
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

        $emailMetrics = $this->channelAuditSummary('email');

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
    private function telegramCard(): array
    {
        if (! $this->systemSettings->getBool('notifications.telegram.enabled', false)) {
            return $this->integrationCard('telegram', 'Telegram', OperationsHealthStatus::Disabled, 'Telegram notifications are disabled.');
        }

        if (! $this->systemSettings->getBool('telegram.api_enabled', false)) {
            return $this->integrationCard('telegram', 'Telegram', OperationsHealthStatus::NotConfigured, 'Telegram API is not enabled.');
        }

        $telegramMetrics = $this->channelAuditSummary('telegram');

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
     * @return array{sent: int, failed: int, last_success_at: ?\Illuminate\Support\Carbon}
     */
    private function channelAuditSummary(string $channel): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return ['sent' => 0, 'failed' => 0, 'last_success_at' => null];
        }

        $logs = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->where('created_at', '>=', today())
            ->latest('created_at')
            ->get();

        $sent = 0;
        $failed = 0;
        $lastSuccessAt = null;

        foreach ($logs as $log) {
            $channelResults = $log->new_values['channel_results'] ?? [];

            if (! is_array($channelResults)) {
                continue;
            }

            foreach ($channelResults as $result) {
                if (! is_array($result) || ($result['channel'] ?? null) !== $channel) {
                    continue;
                }

                if (($result['success'] ?? false) === true && ($result['status'] ?? '') !== 'not_yet_configured') {
                    $sent++;
                    $lastSuccessAt ??= $log->created_at;
                } elseif (($result['status'] ?? '') !== 'not_yet_configured') {
                    $failed++;
                }
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'last_success_at' => $lastSuccessAt,
        ];
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
