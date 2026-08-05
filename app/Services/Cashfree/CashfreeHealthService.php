<?php

namespace App\Services\Cashfree;

use App\Data\Cashfree\CashfreeHealthReport;
use App\Enums\OutboxEventStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Models\CashfreeWebhookLog;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight Cashfree self-test for Platform Health and pre-flight guards.
 *
 * Read-only: never creates or mutates business data.
 */
class CashfreeHealthService
{
    public const SYSTEM_USER_STATUS_HEALTHY = 'healthy';

    public const SYSTEM_USER_STATUS_MISSING = 'missing';

    public const ERROR_SYSTEM_USER_EMAIL_MISSING = 'Cashfree system user email is not configured (CASHFREE_SYSTEM_USER_EMAIL).';

    public const ERROR_SYSTEM_USER_NOT_FOUND = 'Cashfree system user was not found for the configured email.';

    public const ERROR_SYSTEM_USER_INACTIVE = 'Cashfree system user exists but is inactive or soft-deleted.';

    public function __construct(
        private readonly CashfreeConfigurationValidator $configurationValidator,
        private readonly QueueMetricsService $queueMetricsService,
    ) {}

    public function configuredSystemUserEmail(): string
    {
        return trim((string) config('cashfree.system_user_email'));
    }

    public function resolveSystemUser(): ?User
    {
        $email = $this->configuredSystemUserEmail();

        if ($email === '' || ! Schema::hasTable('users')) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    /**
     * @return array{status: string, label: string, email: string, role_label: ?string, failure: ?string, user: ?User}
     */
    public function systemUserCheck(): array
    {
        $email = $this->configuredSystemUserEmail();

        if ($email === '') {
            return [
                'status' => self::SYSTEM_USER_STATUS_MISSING,
                'label' => 'Missing',
                'email' => '',
                'role_label' => null,
                'failure' => self::ERROR_SYSTEM_USER_EMAIL_MISSING,
                'user' => null,
            ];
        }

        if (! Schema::hasTable('users')) {
            return [
                'status' => self::SYSTEM_USER_STATUS_MISSING,
                'label' => 'Missing',
                'email' => $email,
                'role_label' => null,
                'failure' => self::ERROR_SYSTEM_USER_NOT_FOUND.' ('.$email.')',
                'user' => null,
            ];
        }

        $user = $this->resolveSystemUser();

        if ($user === null) {
            return [
                'status' => self::SYSTEM_USER_STATUS_MISSING,
                'label' => 'Missing',
                'email' => $email,
                'role_label' => null,
                'failure' => self::ERROR_SYSTEM_USER_NOT_FOUND.' ('.$email.')',
                'user' => null,
            ];
        }

        $trashed = method_exists($user, 'trashed') && $user->trashed();

        if ($trashed || ! $user->is_active) {
            return [
                'status' => self::SYSTEM_USER_STATUS_MISSING,
                'label' => 'Missing',
                'email' => $email,
                'role_label' => $this->primaryRoleLabel($user),
                'failure' => self::ERROR_SYSTEM_USER_INACTIVE.' ('.$email.')',
                'user' => $user,
            ];
        }

        return [
            'status' => self::SYSTEM_USER_STATUS_HEALTHY,
            'label' => 'Healthy',
            'email' => $email,
            'role_label' => $this->primaryRoleLabel($user),
            'failure' => null,
            'user' => $user,
        ];
    }

    /**
     * Failures that must block payment processing (config + system user).
     *
     * @return list<string>
     */
    public function blockingFailures(): array
    {
        $failures = $this->configurationValidator->failures();

        // Surface signature/secret misconfiguration first for existing health probes.
        if ($failures !== []) {
            return $failures;
        }

        $systemUser = $this->systemUserCheck();

        if (is_string($systemUser['failure'])) {
            $failures[] = $systemUser['failure'];
        }

        return array_values(array_unique($failures));
    }

    public function assertSystemUserReady(): User
    {
        $check = $this->systemUserCheck();

        if ($check['status'] !== self::SYSTEM_USER_STATUS_HEALTHY || ! $check['user'] instanceof User) {
            throw new \RuntimeException($check['failure'] ?? self::ERROR_SYSTEM_USER_NOT_FOUND);
        }

        return $check['user'];
    }

    public function status(): CashfreeHealthReport
    {
        $systemUser = $this->systemUserCheck();
        $verifySignature = (bool) config('cashfree.verify_signature');
        $secretConfigured = trim((string) config('cashfree.client_secret')) !== '';
        $databaseReady = Schema::hasTable('cashfree_webhook_logs')
            && Schema::hasTable('outbox_events');

        $configFailures = $this->configurationValidator->failures();
        $blocking = $this->blockingFailures();

        $webhookSecretStatus = match (true) {
            ! $verifySignature => 'not_required',
            $secretConfigured => 'configured',
            default => 'missing',
        };

        $webhookSecretLabel = match ($webhookSecretStatus) {
            'configured' => 'Configured',
            'not_required' => 'Not required',
            default => 'Missing',
        };

        $latestWebhookAt = null;
        $lastSuccessfulPaymentAt = null;
        $lastFailedPaymentAt = null;

        if (Schema::hasTable('cashfree_webhook_logs')) {
            $latestWebhookAt = CashfreeWebhookLog::query()->latest('received_at')->value('received_at');
            $lastSuccessfulPaymentAt = CashfreeWebhookLog::query()
                ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
                ->latest('processed_at')
                ->value('processed_at');
            $lastFailedPaymentAt = CashfreeWebhookLog::query()
                ->where('processing_status', CashfreeWebhookProcessorService::STATUS_FAILED)
                ->latest('processed_at')
                ->value('processed_at');
        }

        $outboxPending = 0;
        $outboxFailed = 0;

        if (Schema::hasTable('outbox_events')) {
            $outboxPending = OutboxEvent::query()
                ->where('event_type', CashfreeWebhookOutboxWriter::EVENT_TYPE)
                ->where('status', OutboxEventStatus::Pending)
                ->count();
            $outboxFailed = OutboxEvent::query()
                ->where('event_type', CashfreeWebhookOutboxWriter::EVENT_TYPE)
                ->where('status', OutboxEventStatus::Failed)
                ->count();
        }

        $queueSnapshot = $this->queueMetricsService->capture();

        $overallStatus = $blocking === [] && $databaseReady ? 'healthy' : 'critical';
        $overallLabel = $overallStatus === 'healthy' ? 'Healthy' : 'Needs attention';

        return new CashfreeHealthReport(
            overallStatus: $overallStatus,
            overallStatusLabel: $overallLabel,
            systemUserStatus: $systemUser['status'],
            systemUserStatusLabel: $systemUser['label'],
            configuredEmail: $systemUser['email'],
            systemUserRoleLabel: $systemUser['role_label'],
            webhookSecretStatus: $webhookSecretStatus,
            webhookSecretStatusLabel: $webhookSecretLabel,
            verifySignatureEnabled: $verifySignature,
            databaseReady: $databaseReady,
            outboxPending: $outboxPending,
            outboxFailed: $outboxFailed,
            queuePending: $queueSnapshot->pendingJobs,
            queueFailed: $queueSnapshot->failedJobs,
            latestWebhookAt: $latestWebhookAt,
            lastSuccessfulPaymentAt: $lastSuccessfulPaymentAt,
            lastFailedPaymentAt: $lastFailedPaymentAt,
            failures: $blocking,
            checks: [
                'configuration' => $configFailures === [],
                'system_user' => $systemUser['status'] === self::SYSTEM_USER_STATUS_HEALTHY,
                'database' => $databaseReady,
                'webhook_secret' => $webhookSecretStatus !== 'missing',
                'dependencies' => true,
            ],
        );
    }

    private function primaryRoleLabel(User $user): ?string
    {
        $user->loadMissing('roles');
        $role = $user->roles->first();

        if ($role === null) {
            return null;
        }

        $name = trim((string) ($role->name ?? ''));

        return $name !== '' ? $name : null;
    }
}
