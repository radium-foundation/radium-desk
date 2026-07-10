<?php

namespace App\Services\Operations;

use App\Data\Operations\IraCommunicationInput;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOwnerReportData;
use App\Data\Operations\SupportSlotReminderItem;
use App\Data\Operations\TeamWorkBriefing;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IraNotificationType;
use App\Enums\NotificationChannelType;
use App\Enums\OperationQueue;
use App\Enums\OperationsHealthStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Notifications\IraNotificationCategoryMapper;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Telegram\TelegramBotService;
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
 * The standard {@see NotificationDispatcher} stack
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
    ];

    public function __construct(
        private readonly IraNotificationService $notificationService,
        private readonly TelegramBotService $telegramBot,
        private readonly OperationsRoleService $roleService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly IraBriefingFormatter $briefingFormatter,
        private readonly IraOwnerReportFormatter $ownerReportFormatter,
        private readonly TeamWorkBriefingFormatter $teamBriefingFormatter,
        private readonly NotificationRecipientResolver $recipientResolver,
        private readonly NotificationAuthorityService $notificationAuthority,
        private readonly IraNotificationPolicyService $notificationPolicy,
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

            if (! $this->notificationAuthority->shouldDeliver(
                $user,
                IraNotificationCategoryMapper::toNotificationCategory($input->event),
                NotificationChannelType::Telegram,
                now(),
                iraTelegramBridge: true,
            )) {
                $results[] = $this->notificationService->markSkipped(
                    $notification,
                    'Telegram notifications disabled or chat ID not configured.',
                );

                continue;
            }

            if ($this->shouldDeferAssignmentTelegram($user, $input)) {
                $results[] = $this->notificationService->markSkipped(
                    $notification,
                    'Assignee is outside working hours; Telegram deferred.',
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

            $telegramParts = $this->telegramMessageParts($user, $input, $message);
            $deliveryFailed = false;
            $deliveryError = null;

            foreach ($telegramParts as $part) {
                $sendResult = $this->telegramBot->sendMessage(
                    chatId: (string) $user->telegram_chat_id,
                    text: $part,
                );

                if (! $sendResult->success) {
                    $deliveryFailed = true;
                    $deliveryError = (string) $sendResult->error;

                    break;
                }
            }

            if ($deliveryFailed) {
                $results[] = $this->notificationService->markFailed(
                    $notification,
                    (string) $deliveryError,
                );

                continue;
            }

            $results[] = $this->notificationService->markSent($notification);
            $this->recordCooldown($user, $input);
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
     * @return list<User>
     */
    public function opsDigestRecipients(): array
    {
        return $this->operationsAdminUsers()->all();
    }

    /**
     * @return list<User>
     */
    public function ownerIntelligenceRecipients(): array
    {
        return $this->ownerUsers()->all();
    }

    /**
     * @return list<IraNotification>
     */
    public function sendOwnerIntelligenceReport(User $user, IraOwnerReportData $report): array
    {
        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::OwnerIntelligenceReport,
            context: [
                'user_id' => $user->id,
                'report' => $report,
                'dedupe_key' => 'owner_intel:'.$report->date.':'.$report->period,
            ],
        ));
    }

    /**
     * @return list<IraNotification>
     */
    public function sendOpsDigest(User $user, IraMorningBriefing $briefing, string $period): array
    {
        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::OpsDigest,
            context: [
                'user_id' => $user->id,
                'briefing' => $briefing,
                'dedupe_key' => 'ops_digest:'.$briefing->snapshot->date.':'.$period,
            ],
        ));
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
    public function sendTeamDailyBriefing(User $user, TeamWorkBriefing $briefing): array
    {
        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::TeamDailyBriefing,
            context: [
                'user_id' => $user->id,
                'briefing' => $briefing,
                'dedupe_key' => 'team_daily:'.$briefing->date,
            ],
        ));
    }

    /**
     * @param  list<SupportSlotReminderItem>  $items
     * @return list<IraNotification>
     */
    public function sendSupportSlotReminder(
        User $user,
        SupportAppointmentTimeSlot $slot,
        array $items,
        ?string $date = null,
    ): array {
        $date ??= now()->toDateString();

        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::SupportSlotReminder,
            context: [
                'user_id' => $user->id,
                'slot' => $slot->value,
                'items' => array_map(
                    fn (SupportSlotReminderItem $item): array => $item->toArray(),
                    $items,
                ),
                'dedupe_key' => 'slot:'.$date.':'.$slot->value,
            ],
        ));
    }

    /**
     * @return list<IraNotification>
     */
    public function sendManualAssignment(
        User $assignee,
        string $customer,
        string $device,
        string $time,
        string $caseReference,
        array $context = [],
    ): array {
        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::ManualAssignment,
            context: [
                'user_id' => $assignee->id,
                'customer' => $customer,
                'device' => $device,
                'time' => $time,
                'case' => $caseReference,
                'dedupe_key' => 'assignment:'.($context['incident_id'] ?? uniqid()).':'.$assignee->id,
                ...$context,
            ],
        ));
    }

    /**
     * @return list<IraNotification>
     */
    public function sendReassignment(
        User $assignee,
        string $customer,
        string $device,
        string $time,
        string $caseReference,
        array $context = [],
    ): array {
        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::Reassignment,
            context: [
                'user_id' => $assignee->id,
                'customer' => $customer,
                'device' => $device,
                'time' => $time,
                'case' => $caseReference,
                'dedupe_key' => 'assignment:'.($context['incident_id'] ?? uniqid()).':'.$assignee->id,
                ...$context,
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
                'dedupe_key' => 'assignment:'.($context['incident_id'] ?? $context['appointment_id'] ?? uniqid()).':'.$assignee->id,
                ...$context,
            ],
        ));
    }

    /**
     * @return list<IraNotification>
     */
    public function sendCriticalAlerts(): array
    {
        $watchdog = app(ProductionWatchdogService::class);
        $results = [];

        foreach ($watchdog->collectCriticalAlerts() as $alert) {
            $results = [
                ...$results,
                ...$this->dispatch(new IraCommunicationInput(
                    event: IraNotificationType::CriticalSystemAlert,
                    context: $alert->toContext(),
                )),
            ];
        }

        return $results;
    }

    /**
     * @return list<IraNotification>
     */
    public function sendTeamAnnouncement(
        User $recipient,
        string $message,
        User $sender,
        ?string $subject = null,
    ): array {
        $dedupeKey = 'announcement:'.md5($message.':'.$recipient->id.':'.now()->timestamp);

        return $this->dispatch(new IraCommunicationInput(
            event: IraNotificationType::TeamAnnouncement,
            context: [
                'user_id' => $recipient->id,
                'message' => $message,
                'subject' => $subject,
                'sender_name' => $sender->name,
                'dedupe_key' => $dedupeKey,
                'force_notify' => true,
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
            IraNotificationType::IntegrationFailure,
            IraNotificationType::CriticalSystemAlert => $this->ownerUsers(),
            IraNotificationType::UnassignedScheduledWork,
            IraNotificationType::WaitingCustomerRisk,
            IraNotificationType::TeamAvailabilityIssue,
            IraNotificationType::OpsDigest => $this->operationsAdminUsers(),
            IraNotificationType::SmartAssignment,
            IraNotificationType::ManualAssignment,
            IraNotificationType::Reassignment,
            IraNotificationType::TeamDailyBriefing,
            IraNotificationType::SupportSlotReminder,
            IraNotificationType::TeamAnnouncement => collect(),
        };
    }

    private function shouldDeferAssignmentTelegram(User $user, IraCommunicationInput $input): bool
    {
        if (! in_array($input->event, [
            IraNotificationType::ManualAssignment,
            IraNotificationType::Reassignment,
            IraNotificationType::SmartAssignment,
        ], true)) {
            return false;
        }

        $incident = $this->resolveIncidentFromInput($input);

        return ! $this->notificationPolicy->canNotifyNowWithContext(
            $user,
            $incident,
            $input->context,
        );
    }

    private function resolveIncidentFromInput(IraCommunicationInput $input): ?Incident
    {
        $incidentId = $input->context['incident_id'] ?? null;

        if (! is_numeric($incidentId)) {
            return null;
        }

        return Incident::query()->find((int) $incidentId);
    }

    private function isImportantEnough(IraCommunicationInput $input): bool
    {
        return match ($input->event) {
            IraNotificationType::DailyBriefing,
            IraNotificationType::TeamDailyBriefing,
            IraNotificationType::OpsDigest,
            IraNotificationType::OwnerIntelligenceReport,
            IraNotificationType::SmartAssignment,
            IraNotificationType::ManualAssignment,
            IraNotificationType::Reassignment,
            IraNotificationType::SupportSlotReminder,
            IraNotificationType::TeamAnnouncement => true,
            IraNotificationType::IntegrationFailure => true,
            IraNotificationType::CriticalSystemAlert => true,
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
        if (in_array($input->event, [
            IraNotificationType::DailyBriefing,
            IraNotificationType::TeamDailyBriefing,
            IraNotificationType::OpsDigest,
            IraNotificationType::OwnerIntelligenceReport,
            IraNotificationType::SupportSlotReminder,
        ], true)) {
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
        $ttlSeconds = in_array($input->event, [
            IraNotificationType::DailyBriefing,
            IraNotificationType::TeamDailyBriefing,
            IraNotificationType::OpsDigest,
            IraNotificationType::OwnerIntelligenceReport,
            IraNotificationType::SupportSlotReminder,
        ], true)
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

    /**
     * @return array{0: string, 1: string}
     */
    private function formatMessage(User $user, IraCommunicationInput $input): array
    {
        return match ($input->event) {
            IraNotificationType::DailyBriefing => $this->formatDailyBriefing($user, $input),
            IraNotificationType::OpsDigest => $this->formatOpsDigest($user, $input),
            IraNotificationType::OwnerIntelligenceReport => $this->formatOwnerIntelligenceReport($user, $input),
            IraNotificationType::TeamDailyBriefing => $this->formatTeamDailyBriefing($user, $input),
            IraNotificationType::SmartAssignment,
            IraNotificationType::ManualAssignment => $this->formatAssignment($input, 'New support assigned'),
            IraNotificationType::Reassignment => $this->formatAssignment($input, 'Support reassigned to you'),
            IraNotificationType::SupportSlotReminder => $this->formatSupportSlotReminder($input),
            IraNotificationType::IntegrationFailure => $this->formatIntegrationFailure($input),
            IraNotificationType::CriticalSystemAlert => $this->formatCriticalSystemAlert($input),
            IraNotificationType::TeamAnnouncement => $this->formatTeamAnnouncement($input),
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
    private function formatOpsDigest(User $user, IraCommunicationInput $input): array
    {
        $briefing = $input->context['briefing'] ?? null;

        if (! $briefing instanceof IraMorningBriefing) {
            return ['Operations Digest', 'Operations digest is unavailable.'];
        }

        $message = $this->briefingFormatter->formatOpsDigest(
            briefing: $briefing,
            recipientFirstName: $user->firstName() ?: null,
        );

        return ['Operations Digest', $message];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatOwnerIntelligenceReport(User $user, IraCommunicationInput $input): array
    {
        $report = $input->context['report'] ?? null;

        if (! $report instanceof IraOwnerReportData) {
            return ['Owner Intelligence Report', 'Owner intelligence report is unavailable.'];
        }

        $parts = $this->ownerReportFormatter->formatTelegramMessages(
            report: $report,
            recipientFirstName: $user->firstName() ?: null,
        );

        $title = $report->period === 'evening'
            ? 'Owner Performance Report'
            : 'Owner Intelligence Report';

        return [$title, implode("\n\n---\n\n", $parts)];
    }

    /**
     * @return list<string>
     */
    private function telegramMessageParts(User $user, IraCommunicationInput $input, string $message): array
    {
        if ($input->event !== IraNotificationType::OwnerIntelligenceReport) {
            return [$message];
        }

        $report = $input->context['report'] ?? null;

        if (! $report instanceof IraOwnerReportData) {
            return [$message];
        }

        return $this->ownerReportFormatter->formatTelegramMessages(
            report: $report,
            recipientFirstName: $user->firstName() ?: null,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatTeamDailyBriefing(User $user, IraCommunicationInput $input): array
    {
        $briefing = $input->context['briefing'] ?? null;

        if (! $briefing instanceof TeamWorkBriefing) {
            return ['Team Daily Briefing', 'Work briefing is unavailable.'];
        }

        $message = $this->teamBriefingFormatter->format(
            briefing: $briefing,
            recipientFirstName: $user->firstName() ?: null,
        );

        return ['Team Daily Briefing', $message];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatAssignment(IraCommunicationInput $input, string $heading): array
    {
        $message = implode("\n", [
            $heading,
            '',
            'Customer: '.($input->context['customer'] ?? 'Unknown'),
            'Device: '.($input->context['device'] ?? 'Unknown'),
            'Time: '.($input->context['time'] ?? 'Unknown'),
            'Open case: '.($input->context['case'] ?? 'Unknown'),
        ]);

        return ['Support Assigned', $message];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatSupportSlotReminder(IraCommunicationInput $input): array
    {
        $slot = SupportAppointmentTimeSlot::tryFrom((string) ($input->context['slot'] ?? ''));

        $heading = match ($slot) {
            SupportAppointmentTimeSlot::Morning => 'Morning Support Queue',
            SupportAppointmentTimeSlot::Afternoon => 'Afternoon Support Queue',
            SupportAppointmentTimeSlot::Evening => 'Evening Support Queue',
            default => 'Support Queue',
        };

        $lines = [
            $heading,
            '',
            'You have:',
            '',
        ];

        $items = $input->context['items'] ?? [];
        $index = 1;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lines[] = sprintf(
                '%d. %s - %s',
                $index,
                (string) ($item['customer_name'] ?? 'Customer'),
                (string) ($item['device_model'] ?? 'Unknown'),
            );
            $index++;
        }

        if ($index === 1) {
            $lines[] = 'No scheduled support cases in this slot.';
        }

        $lines[] = '';
        $lines[] = 'Review in My Work.';

        return [$heading, implode("\n", $lines)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatSmartAssignment(IraCommunicationInput $input): array
    {
        return $this->formatAssignment($input, 'New support assigned');
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
    private function formatCriticalSystemAlert(IraCommunicationInput $input): array
    {
        $label = (string) ($input->context['label'] ?? 'System');
        $detail = (string) ($input->context['message'] ?? 'Critical system issue detected.');
        $affectedCount = (int) ($input->context['affected_count'] ?? 0);
        $orderIds = $input->context['order_ids'] ?? [];

        $lines = [
            '🚨 Critical Alert',
            '',
            "{$label}: {$detail}",
        ];

        if ($affectedCount > 0) {
            $lines[] = '';
            $lines[] = "Affected: {$affectedCount}";
        }

        if (is_array($orderIds) && $orderIds !== []) {
            $visible = array_slice(array_map('strval', $orderIds), 0, 5);
            $lines[] = 'Orders: '.implode(', ', $visible);

            if (count($orderIds) > 5) {
                $lines[] = '+'.(count($orderIds) - 5).' more';
            }
        }

        return ["{$label} Alert", implode("\n", $lines)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatTeamAnnouncement(IraCommunicationInput $input): array
    {
        $subject = trim((string) ($input->context['subject'] ?? ''));
        $senderName = (string) ($input->context['sender_name'] ?? 'Management');
        $message = trim((string) ($input->context['message'] ?? ''));

        $lines = [
            $subject !== '' ? "📢 {$subject}" : '📢 Team Announcement',
            '',
            "From: {$senderName}",
            '',
            $message !== '' ? $message : 'No message body provided.',
        ];

        return ['Team Announcement', implode("\n", $lines)];
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

        if (($context['briefing'] ?? null) instanceof TeamWorkBriefing) {
            $context['briefing'] = $context['briefing']->toArray();
        }

        if (($context['report'] ?? null) instanceof IraOwnerReportData) {
            $context['report'] = $context['report']->toArray();
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
        $snapshot = DashboardSnapshot::load();

        return $snapshot
            ->incidentsForQueue(OperationQueue::Scheduled->value)
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === null)
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

            if ($status !== OperationsHealthStatus::Failed) {
                continue;
            }

            $key = (string) ($card['key'] ?? 'integration');

            // Cashfree missing-order healing is owned by cashfree:auto-recover-missing.
            // That path notifies Ira only when automatic recovery fails, avoiding hourly noise.
            if ($key === 'cashfree' && (bool) config('cashfree.auto_recover.enabled', true)) {
                continue;
            }

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
        return $this->recipientResolver->ownerRecipients();
    }

    /**
     * @return Collection<int, User>
     */
    private function operationsAdminUsers(): Collection
    {
        return $this->recipientResolver->operationalRecipients();
    }
}
