<?php

namespace App\Services;

use App\Data\NotificationMessage;
use App\Enums\CommunicationActionKey;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\User;
use App\Notifications\RefundRequestDecisionNotification;
use App\Notifications\RefundRequestSubmittedNotification;
use App\Services\CommunicationActions\CommunicationActionLifecycleService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionVariableResolver;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Telegram\TelegramBotService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefundNotificationService
{
    public function __construct(
        private readonly NotificationAuthorityService $notificationAuthority,
        private readonly TelegramBotService $telegramBot,
        private readonly AuditLogService $auditLogService,
        private readonly CommunicationActionRegistry $communicationActionRegistry,
        private readonly CommunicationActionVariableResolver $variableResolver,
        private readonly CommunicationActionLifecycleService $lifecycleService,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {}

    public function notifyApproversOfSubmission(RefundRequest $refund): void
    {
        foreach ($this->eligibleApprovers() as $approver) {
            $this->dispatchAgentFacingNotification(
                recipient: $approver,
                refund: $refund,
                inAppNotification: new RefundRequestSubmittedNotification($refund),
                telegramTitle: 'Refund Request Submitted',
                telegramMessage: $this->formatSubmittedTelegramMessage($refund),
                trigger: 'submitted',
            );
        }
    }

    public function notifyRequesterOfDecision(RefundRequest $refund, string $trigger): void
    {
        $requester = $refund->requester;

        if ($requester === null || ! $requester->is_active) {
            return;
        }

        try {
            $this->dispatchAgentFacingNotification(
                recipient: $requester,
                refund: $refund,
                inAppNotification: new RefundRequestDecisionNotification($refund, $trigger),
                telegramTitle: $this->decisionTelegramTitle($refund),
                telegramMessage: $this->formatDecisionTelegramMessage($refund),
                trigger: $trigger,
            );
        } catch (Throwable $exception) {
            Log::warning('[Refunds] Requester notification failed; workflow continued.', [
                'refund_id' => $refund->id,
                'reference_no' => $refund->reference_no,
                'trigger' => $trigger,
                'message' => $exception->getMessage(),
            ]);

            $this->auditLogService->log(
                userId: $refund->requested_by,
                event: 'refund.requester_notification_failed',
                auditable: $refund,
                newValues: [
                    'recipient_id' => $requester->id,
                    'trigger' => $trigger,
                    'status' => $refund->status->value,
                    'reference_no' => $refund->reference_no,
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    /**
     * Send refund_confirmation via the Communication Actions framework.
     *
     * Best-effort communication only — callers must not gate business close on the result.
     *
     * @param  list<string>|null  $channels
     * @return bool|null true when sent, false when attempted but failed, null when skipped
     */
    public function notifyCustomer(
        RefundRequest $refund,
        User $actor,
        ?array $channels = null,
        ?Request $request = null,
    ): ?bool {
        $refund->loadMissing(['incident.order', 'order']);

        $selectedChannels = $channels ?? $refund->communication_channels ?? ['email', 'whatsapp'];
        $selectedChannels = array_values(array_filter(
            array_map(static fn ($channel): string => (string) $channel, $selectedChannels),
        ));

        if ($selectedChannels === []) {
            $this->auditCustomerNotification(
                refund: $refund,
                actor: $actor,
                channels: $selectedChannels,
                outcome: 'skipped',
                success: null,
                request: $request,
                reason: 'No communication channels selected.',
            );

            return null;
        }

        $incident = $refund->incident;

        if ($incident === null || $incident->order === null) {
            $this->auditCustomerNotification(
                refund: $refund,
                actor: $actor,
                channels: $selectedChannels,
                outcome: 'failed',
                success: false,
                request: $request,
                reason: 'Linked service case or order is missing.',
            );

            return false;
        }

        try {
            $definition = $this->communicationActionRegistry->get(
                CommunicationActionKey::RefundConfirmation->value,
            );

            $variables = $this->variableResolver->resolve(
                definition: $definition,
                incident: $incident,
                operatorInput: [],
                operator: $actor,
            );

            $allowedChannels = collect($selectedChannels)
                ->map(fn (string $channel): ?NotificationChannelType => NotificationChannelType::tryFrom($channel))
                ->filter()
                ->values()
                ->all();

            if ($allowedChannels === []) {
                $this->auditCustomerNotification(
                    refund: $refund,
                    actor: $actor,
                    channels: $selectedChannels,
                    outcome: 'failed',
                    success: false,
                    request: $request,
                    reason: 'No valid notification channels were selected.',
                );

                return false;
            }

            $dispatchResult = $this->notificationDispatcher->send(
                $definition->notificationType,
                new NotificationMessage(
                    type: $definition->notificationType,
                    customer: $incident->order,
                    incident: $incident,
                    template: $definition->whatsappTemplate?->value,
                    variables: $variables,
                    metadata: [
                        'source' => 'refund_workflow_complete',
                        'refund_id' => $refund->id,
                        'communication_action_key' => $definition->key->value,
                    ],
                    actor: $actor,
                    httpRequest: $request,
                ),
                allowedChannels: $allowedChannels,
            );

            $results = $dispatchResult->results;
            $allSkipped = $results !== [] && collect($results)->every(
                fn (\App\Data\NotificationResult $result): bool => $result->isSkipped(),
            );

            if ($allSkipped) {
                $this->auditCustomerNotification(
                    refund: $refund,
                    actor: $actor,
                    channels: $selectedChannels,
                    outcome: 'skipped',
                    success: null,
                    request: $request,
                    reason: $dispatchResult->message ?? 'Notification channels skipped (template/channel not configured).',
                );

                return null;
            }

            $success = $dispatchResult->success;

            if ($success) {
                $sentChannels = collect($results)
                    ->filter(fn (\App\Data\NotificationResult $result): bool => $result->countsTowardSuccess())
                    ->map(fn (\App\Data\NotificationResult $result): string => $result->channel->value)
                    ->values()
                    ->all();

                $this->lifecycleService->recordSuccessfulExecution(
                    incident: $incident,
                    actor: $actor,
                    actionKey: CommunicationActionKey::RefundConfirmation->value,
                    channels: $sentChannels,
                    request: $request,
                );

                $this->auditCustomerNotification(
                    refund: $refund,
                    actor: $actor,
                    channels: $selectedChannels,
                    outcome: 'sent',
                    success: true,
                    request: $request,
                    reason: $dispatchResult->message,
                );

                return true;
            }

            Log::warning('[Refunds] Customer notification failed; workflow continued.', [
                'refund_id' => $refund->id,
                'reference_no' => $refund->reference_no,
                'channels' => $selectedChannels,
                'message' => $dispatchResult->message,
            ]);

            $this->auditCustomerNotification(
                refund: $refund,
                actor: $actor,
                channels: $selectedChannels,
                outcome: 'failed',
                success: false,
                request: $request,
                reason: $dispatchResult->message ?? 'Customer notification dispatch failed.',
            );

            return false;
        } catch (Throwable $exception) {
            Log::warning('[Refunds] Customer notification threw; workflow continued.', [
                'refund_id' => $refund->id,
                'reference_no' => $refund->reference_no,
                'channels' => $selectedChannels,
                'message' => $exception->getMessage(),
            ]);

            $this->auditCustomerNotification(
                refund: $refund,
                actor: $actor,
                channels: $selectedChannels,
                outcome: 'failed',
                success: false,
                request: $request,
                reason: $exception->getMessage(),
            );

            return false;
        }
    }

    /**
     * @param  list<string>  $channels
     */
    private function auditCustomerNotification(
        RefundRequest $refund,
        User $actor,
        array $channels,
        string $outcome,
        ?bool $success,
        ?Request $request,
        ?string $reason = null,
    ): void {
        $this->auditLogService->log(
            userId: $actor->id,
            event: 'refund.customer_notified',
            auditable: $refund,
            newValues: array_filter([
                'channels' => $channels,
                'outcome' => $outcome,
                'success' => $success,
                'reason' => $reason,
                'reference_no' => $refund->reference_no,
                'communication_action_key' => CommunicationActionKey::RefundConfirmation->value,
            ], static fn ($value): bool => $value !== null),
            request: $request,
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleApprovers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ]))
            ->get()
            ->filter(fn (User $user): bool => $user->can('refunds.review'))
            ->values();
    }

    private function dispatchAgentFacingNotification(
        User $recipient,
        RefundRequest $refund,
        RefundRequestSubmittedNotification|RefundRequestDecisionNotification $inAppNotification,
        string $telegramTitle,
        string $telegramMessage,
        string $trigger,
    ): void {
        $delivered = false;

        if ($this->notificationAuthority->shouldDeliver(
            $recipient,
            NotificationCategory::Finance,
            NotificationChannelType::InApp,
        )) {
            $recipient->notify($inAppNotification);
            $delivered = true;
        }

        if ($this->notificationAuthority->shouldDeliver(
            $recipient,
            NotificationCategory::Finance,
            NotificationChannelType::Telegram,
        ) && $this->shouldSendRefundTelegram($recipient, $trigger)
            && $this->telegramBot->isConfigured() && filled($recipient->telegram_chat_id)) {
            $sendResult = $this->telegramBot->sendMessage(
                chatId: (string) $recipient->telegram_chat_id,
                text: implode("\n", [$telegramTitle, '', $telegramMessage]),
            );
            $delivered = $delivered || $sendResult->success;
        }

        if ($delivered) {
            $this->auditLogService->log(
                userId: $refund->requested_by,
                event: 'refund.agent_notified',
                auditable: $refund,
                newValues: [
                    'recipient_id' => $recipient->id,
                    'trigger' => $trigger,
                    'status' => $refund->status->value,
                    'reference_no' => $refund->reference_no,
                ],
            );
        }
    }

    private function formatSubmittedTelegramMessage(RefundRequest $refund): string
    {
        $requester = $refund->requester?->name ?? 'Agent';

        return "{$requester} submitted {$refund->reference_no} (₹".number_format($refund->displayAmount(), 2).').';
    }

    private function shouldSendRefundTelegram(User $recipient, string $trigger): bool
    {
        if ($trigger !== 'submitted') {
            return true;
        }

        return ! $recipient->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    private function decisionTelegramTitle(RefundRequest $refund): string
    {
        return match ($refund->status) {
            RefundStatus::Rejected => 'Refund Rejected',
            RefundStatus::Completed, RefundStatus::Closed => 'Refund Completed',
            RefundStatus::PendingExecution => 'Refund Approved',
            default => 'Refund Updated',
        };
    }

    private function formatDecisionTelegramMessage(RefundRequest $refund): string
    {
        return "{$refund->reference_no} is now {$refund->status->label()}.";
    }
}
