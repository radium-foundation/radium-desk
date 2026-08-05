<?php

namespace App\Data\Cashfree;

use Carbon\CarbonInterface;

/**
 * Structured Cashfree self-test / Platform Health report (read-only).
 */
readonly class CashfreeHealthReport
{
    /**
     * @param  list<string>  $failures
     * @param  array<string, mixed>  $checks
     */
    public function __construct(
        public string $overallStatus,
        public string $overallStatusLabel,
        public string $systemUserStatus,
        public string $systemUserStatusLabel,
        public string $configuredEmail,
        public ?string $systemUserRoleLabel,
        public string $webhookSecretStatus,
        public string $webhookSecretStatusLabel,
        public bool $verifySignatureEnabled,
        public bool $databaseReady,
        public int $outboxPending,
        public int $outboxFailed,
        public int $queuePending,
        public int $queueFailed,
        public ?CarbonInterface $latestWebhookAt,
        public ?CarbonInterface $lastSuccessfulPaymentAt,
        public ?CarbonInterface $lastFailedPaymentAt,
        public array $failures,
        public array $checks,
    ) {}

    public function isHealthy(): bool
    {
        return $this->overallStatus === 'healthy';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'overall_status' => $this->overallStatus,
            'overall_status_label' => $this->overallStatusLabel,
            'system_user_status' => $this->systemUserStatus,
            'system_user_status_label' => $this->systemUserStatusLabel,
            'configured_email' => $this->configuredEmail,
            'system_user_role_label' => $this->systemUserRoleLabel,
            'webhook_secret_status' => $this->webhookSecretStatus,
            'webhook_secret_status_label' => $this->webhookSecretStatusLabel,
            'verify_signature_enabled' => $this->verifySignatureEnabled,
            'database_ready' => $this->databaseReady,
            'outbox_pending' => $this->outboxPending,
            'outbox_failed' => $this->outboxFailed,
            'queue_pending' => $this->queuePending,
            'queue_failed' => $this->queueFailed,
            'latest_webhook_at' => $this->latestWebhookAt?->toIso8601String(),
            'last_successful_payment_at' => $this->lastSuccessfulPaymentAt?->toIso8601String(),
            'last_failed_payment_at' => $this->lastFailedPaymentAt?->toIso8601String(),
            'failures' => $this->failures,
            'checks' => $this->checks,
            'is_healthy' => $this->isHealthy(),
        ];
    }
}
