<?php

namespace App\Services\Operations;

use App\Data\Operations\IraCommunicationInput;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRisk;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IraNotificationType;
use App\Enums\OperationsHealthStatus;
use App\Models\IraNotification;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ira operational Telegram communication layer.
 *
 * Owns Telegram delivery for:
 * - Smart assignment alerts (team members)
 * - Daily operational briefings (owners)
 * - High-priority operational risk alerts (owners / ops admins)
 *
 * The standard {@see \App\Services\Notifications\NotificationDispatcher} stack
 * (email, WhatsApp, desktop, incident TelegramChannel) remains responsible for
 * customer-facing and general app notifications.
 */
class IraCommunicationService
{
    /**
     * @var list<string>
     */
    private const HIGH_PRIORITY_RISK_KEYS = [
        'customer.sla_danger',
        'workload.low_staffing',
        'workload.high_open_cases',
        'customer.high_waiting',
    ];

    public function __construct(
        private readonly IraNotificationService $notificationService,
        private readonly TelegramBotService $telegramBot,
        private readonly OperationsRoleService $roleService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly IraBriefingFormatter $briefingFormatter,
    ) {}

    /**
     * @return list<IraNotification>
     */
    public function dispatch(IraCommunicationInput $input): array
    {
        $results = [];

        foreach ($this->resolveRecipients($input) as $user) {
            if (! $this->isImportantEnough($input)) {
                continue;
            }

            if ($this->isOnCooldown($user, $input)) {
                continue;
            }

            [$title, $message] = $this->formatMessage($user, $input);

            $notification = $this->notificationService->create(
                user: $user,
                type: $input->event,
                title: $title,
                message: $message,
                payload: $this->buildPayload($input),
            );

            if (! $this->canDeliverViaTelegram($user)) {
                $results[] = $this->notificationService->markSkipped(
                    $notification,
                    'Telegram notifications disabled or chat ID not configured.',
                );

                continue;
            }

            if (! $this->telegramBot->isConfigured()) {
                $results[] = $this->notificationService->markFailed(
                    $notification,
                    'Telegram bot token is not configured.',
                );

                continue;
            }

            $sendResult = $this->telegramBot->sendMessage(
                chatId: (string) $user->telegram_chat_id,
                text: $message,
            );

            if ($sendResult->success) {
                $results[] = $this->notificationService->markSent($notification);
                $this->recordCooldown($user, $input);
            } else {
                $results[] = $this->notificationService->markFailed(
                    $notification,
                    (string) $sendResult->error,
                );
            }
        }

        return $results;
    }

    /**
     * @return list<User>
     */
    public function dailyBriefingRecipients(): array
    {
        return $this->ownerUsers()->all();
    }

    /**
     * @return list<IraNotification>
     */
    public function sendDailyBriefing(User $user, IraMorningBriefing $briefing): array
    {
        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::DailyBriefing,
            context: [
                'user_id' => $user->id,
                'briefing' => $briefing,
                'dedupe_key' => 'daily:'.$briefing->snapshot->date,
            ],
        ));
    }

    /**
     * @return list<IraNotification>
     */
    public function sendSmartAssignment(
        User $assignee,
        string $customer,
        string $device,
        string $time,
        string $caseReference,
        array $context = [],
    ): array {
        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::SmartAssignment,
            context: [
                'user_id' => $assignee->id,
                'customer' => $customer,
                'device' => $device,
                'time' => $time,
                'case' => $caseReference,
                'dedupe_key' => 'assignment:'.($context['appointment_id'] ?? uniqid()),
                ...$context,
            ],
        ));
    }

    /**
     * @return list<IraNotification>
     */
    public function sendOperationalAlerts(IraMorningBriefing $briefing): array
    {
        $results = $this->sendRiskAlerts($briefing);
        $unassignedCount = $this->unassignedScheduledCount();

        if ($unassignedCount > 0) {
            $results = [
                ...$results,
                ...$this->dispatch(new IraCommunicationInput(
                    event: IraNotificationType::UnassignedScheduledWork,
                    context: [
                        'unassigned_scheduled' => $unassignedCount,
                        'dedupe_key' => 'unassigned_scheduled',
                        'message' => "{$unassignedCount} scheduled case(s) have no assignee.",
                    ],
                )),
            ];
        }

        return $results;
    }

    /**
     * @return list<IraNotification>
     */
    public function sendRiskAlerts(IraMorningBriefing $briefing): array
    {
        $results = [];

        foreach ($this->highPriorityRisks($briefing) as $risk) {
            $type = $this->notificationTypeForRisk($risk);
            $results = [
                ...$results,
                ...$this->dispatch(new IraCommunicationInput(
                    event: $type,
                    insight: $risk,
                    context: ['dedupe_key' => $risk->key],
                )),
            ];
        }

        foreach ($this->integrationFailures() as $failure) {
            $results = [
                ...$results,
                ...$this->dispatch(new IraCommunicationInput(
                    event: IraNotificationType::IntegrationFailure,
                    context: $failure,
                )),
            ];
        }

        return $results;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(IraCommunicationInput $input): Collection
    {
        $explicitUserId = $input->context['user_id'] ?? null;

        if (is_numeric($explicitUserId)) {
            $user = User::query()
                ->where('is_active', true)
                ->find((int) $explicitUserId);

            return $user !== null ? collect([$user]) : collect();
        }

        return match ($input->event) {
            IraNotificationType::DailyBriefing,
            IraNotificationType::RiskAlert,
            IraNotificationType::UnusualBacklog,
            IraNotificationType::IntegrationFailure => $this->ownerUsers(),
            IraNotificationType::UnassignedScheduledWork,
            IraNotificationType::WaitingCustomerRisk,
            IraNotificationType::TeamAvailabilityIssue => $this->operationsAdminUsers(),
            IraNotificationType::SmartAssignment => collect(),
        };
    }

    private function isImportantEnough(IraCommunicationInput $input): bool
    {
        return match ($input->event) {
            IraNotificationType::DailyBriefing,
            IraNotificationType::SmartAssignment => true,
            IraNotificationType::IntegrationFailure => true,
            IraNotificationType::UnassignedScheduledWork => (int) ($input->context['unassigned_scheduled'] ?? $input->recommendation?->context['unassigned_scheduled'] ?? 0) > 0,
            IraNotificationType::WaitingCustomerRisk => $input->insight !== null,
            IraNotificationType::TeamAvailabilityIssue => $input->insight !== null,
            IraNotificationType::UnusualBacklog,
            IraNotificationType::RiskAlert => $input->insight !== null && $this->isHighPriorityRisk($input->insight),
        };
    }

    private function isHighPriorityRisk(IraOperationalRisk $risk): bool
    {
        if (! in_array($risk->key, self::HIGH_PRIORITY_RISK_KEYS, true)) {
            return false;
        }

        if ($risk->key === 'workload.low_staffing') {
            return (int) ($risk->context['available'] ?? 0) === 0;
        }

        return $risk->severity === AIRiskLevel::High
            || ($risk->key === 'customer.sla_danger' && (int) ($risk->context['overdue'] ?? 0) > 0);
    }

    private function isOnCooldown(User $user, IraCommunicationInput $input): bool
    {
        if ($input->event === IraNotificationType::DailyBriefing) {
            return Cache::has($this->cooldownCacheKey($user, $input));
        }

        $cooldownMinutes = (int) config('ira.communication.cooldown_minutes', 60);

        if ($cooldownMinutes <= 0) {
            return false;
        }

        return Cache::has($this->cooldownCacheKey($user, $input));
    }

    private function recordCooldown(User $user, IraCommunicationInput $input): void
    {
        $ttlSeconds = $input->event === IraNotificationType::DailyBriefing
            ? max(60, now()->secondsUntilEndOfDay())
            : max(60, (int) config('ira.communication.cooldown_minutes', 60) * 60);

        Cache::put($this->cooldownCacheKey($user, $input), true, $ttlSeconds);
    }

    private function cooldownCacheKey(User $user, IraCommunicationInput $input): string
    {
        return sprintf(
            'ira:cooldown:%d:%s:%s',
            $user->id,
            $input->event->value,
            $input->dedupeKey(),
        );
    }

    private function canDeliverViaTelegram(User $user): bool
    {
        return $user->telegram_notifications_enabled
            && is_string($user->telegram_chat_id)
            && trim($user->telegram_chat_id) !== '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatMessage(User $user, IraCommunicationInput $input): array
    {
        return match ($input->event) {
            IraNotificationType::DailyBriefing => $this->formatDailyBriefing($user, $input),
            IraNotificationType::SmartAssignment => $this->formatSmartAssignment($input),
            IraNotificationType::IntegrationFailure => $this->formatIntegrationFailure($input),
            default => $this->formatRiskAlert($input),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatDailyBriefing(User $user, IraCommunicationInput $input): array
    {
        $briefing = $input->context['briefing'] ?? null;

        if (! $briefing instanceof IraMorningBriefing) {
            return ['Daily Ira Briefing', 'Ira briefing is unavailable.'];
        }

        $formatted = $this->briefingFormatter->format(
            briefing: $briefing,
            recipientFirstName: $user->firstName() ?: null,
        );

        return ['Daily Ira Briefing', $formatted->telegramMessage];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatSmartAssignment(IraCommunicationInput $input): array
    {
        $message = implode("\n", [
            'New support assigned',
            '',
            'Customer: '.($input->context['customer'] ?? 'Unknown'),
            'Device: '.($input->context['device'] ?? 'Unknown'),
            'Time: '.($input->context['time'] ?? 'Unknown'),
            'Open case: '.($input->context['case'] ?? 'Unknown'),
        ]);

        return ['New Support Assigned', $message];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatRiskAlert(IraCommunicationInput $input): array
    {
        $risk = $input->insight;
        $title = $risk?->title ?? $input->event->label();
        $message = $risk?->message ?? ($input->context['message'] ?? 'Operational attention required.');

        return [$title, $message];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatIntegrationFailure(IraCommunicationInput $input): array
    {
        $integration = (string) ($input->context['label'] ?? 'Integration');
        $detail = (string) ($input->context['message'] ?? 'Integration health check failed.');

        return [
            "{$integration} Failure",
            "System issue detected:\n\n{$integration}: {$detail}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(IraCommunicationInput $input): array
    {
        $context = $input->context;

        if (($context['briefing'] ?? null) instanceof IraMorningBriefing) {
            $context['briefing'] = $context['briefing']->toArray();
        }

        return array_filter([
            'event' => $input->event->value,
            'insight' => $input->insight?->toArray(),
            'recommendation' => $input->recommendation?->toArray(),
            'context' => $context,
        ]);
    }

    /**
     * @return list<IraOperationalRisk>
     */
    private function highPriorityRisks(IraMorningBriefing $briefing): array
    {
        $filtered = [];

        foreach ($briefing->risks as $risk) {
            if (! $this->isHighPriorityRisk($risk)) {
                continue;
            }

            $filtered[] = $risk;
        }

        return $filtered;
    }

    private function notificationTypeForRisk(IraOperationalRisk $risk): IraNotificationType
    {
        return match ($risk->key) {
            'customer.high_waiting' => IraNotificationType::WaitingCustomerRisk,
            'team.unavailable', 'workload.low_staffing' => IraNotificationType::TeamAvailabilityIssue,
            'workload.high_open_cases' => IraNotificationType::UnusualBacklog,
            default => IraNotificationType::RiskAlert,
        };
    }

    private function unassignedScheduledCount(): int
    {
        $snapshot = \App\Services\Dashboard\DashboardSnapshot::load();

        return $snapshot
            ->incidentsForQueue(\App\Enums\OperationQueue::Scheduled->value)
            ->filter(fn (\App\Models\Incident $incident): bool => $incident->assigned_to_user_id === null)
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function integrationFailures(): array
    {
        $failures = [];

        foreach ($this->integrationHealthService->cards() as $card) {
            $status = OperationsHealthStatus::tryFrom((string) ($card['status'] ?? ''));

            if ($status !== OperationsHealthStatus::Warning) {
                continue;
            }

            $key = (string) ($card['key'] ?? 'integration');

            $failures[] = [
                'label' => (string) ($card['label'] ?? 'Integration'),
                'message' => (string) ($card['detail'] ?? 'Integration health warning.'),
                'dedupe_key' => 'integration:'.$key,
            ];
        }

        return $failures;
    }

    /**
     * @return Collection<int, User>
     */
    private function ownerUsers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', RolePermissionSeeder::ROLE_SUPERADMIN))
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function operationsAdminUsers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_ADMIN,
            ]))
            ->get();
    }
}
