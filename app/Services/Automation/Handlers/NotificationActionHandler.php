<?php

namespace App\Services\Automation\Handlers;

use App\Contracts\Automation\ActionHandler;
use App\Data\Automation\ActionHandlerResult;
use App\Data\Automation\PlannedAutomationAction;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\AutomationPolicyActionType;
use App\Enums\NotificationChannelType;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Services\Automation\AutomationNotificationTypeResolver;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\Notifications\CustomerAutomationEligibilityService;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use App\Services\Notifications\NotificationDispatcher;

class NotificationActionHandler implements ActionHandler
{
    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly AutomationNotificationTypeResolver $notificationTypeResolver,
        private readonly NotificationDeliverySummaryFormatter $deliverySummaryFormatter,
        private readonly CustomerWaitingLifecycleService $customerWaitingLifecycleService,
        private readonly CustomerAutomationEligibilityService $customerAutomationEligibility,
    ) {}

    public function supports(AutomationPolicyActionType $type): bool
    {
        return $type === AutomationPolicyActionType::WhatsAppTemplate;
    }

    public function handle(PlannedAutomationAction $action): ActionHandlerResult
    {
        $notificationType = $this->notificationTypeResolver->resolve($action->actionKey);

        if ($notificationType === null) {
            return ActionHandlerResult::failure(
                "No notification mapping exists for action key [{$action->actionKey}].",
            );
        }

        $waitingState = $action->waitingState;
        $waitingState->loadMissing(['incident.order']);

        $incident = $waitingState->incident;
        $order = $incident?->order;

        if ($incident === null || $order === null) {
            return ActionHandlerResult::failure('Incident order context is required for notification actions.');
        }

        $blockReason = $this->customerAutomationEligibility->blockReason($incident);

        if ($blockReason !== null) {
            return ActionHandlerResult::skipped(
                'Automated customer notification blocked for enquiry/spam case.',
                metadata: [
                    'blocked' => true,
                    'block_reason' => $blockReason,
                ],
            );
        }

        if ($action->actionKey === 'customer_waiting_followup'
            && ! CustomerWaitingLifecycleService::shouldNotifyCustomerOnAutoClose(
                $action->scheduledAt,
            )) {
            $this->customerWaitingLifecycleService->recordFollowupSent(
                $waitingState,
                $action->scheduledAt,
            );

            return ActionHandlerResult::skipped(
                'Follow-up suppressed for overdue waiting customer; stamped for silent auto-close.',
                metadata: [
                    'suppressed_overdue' => true,
                    'scheduled_at' => $action->scheduledAt->toIso8601String(),
                ],
            );
        }

        $dispatchResult = $this->notificationDispatcher->send(
            $notificationType,
            new NotificationMessage(
                type: $notificationType,
                customer: $order,
                incident: $incident,
                metadata: [
                    'source' => 'automation_runtime',
                    'trigger_source' => WhatsAppTemplateTriggerSource::Automation->value,
                    'waiting_state_id' => $waitingState->id,
                    'policy_key' => $action->policyKey,
                    'schedule_step' => $action->scheduleStep,
                    'action_key' => $action->actionKey,
                ],
            ),
        );

        $channelResults = array_map(
            fn ($result): array => [
                'channel' => $result->channel->value,
                'success' => $result->success,
                'external_id' => $result->external_id,
                'message' => $result->message,
                'retryable' => $result->retryable,
                'metadata' => $result->metadata,
            ],
            $dispatchResult->results,
        );

        $metadata = [
            'notification_type' => $notificationType->value,
            'channel_results' => $channelResults,
        ];

        if (! $dispatchResult->success) {
            return ActionHandlerResult::failure(
                $this->deliverySummaryFormatter->failureMessage($dispatchResult),
                metadata: $metadata,
            );
        }

        if ($action->actionKey === 'customer_waiting_followup') {
            $this->customerWaitingLifecycleService->recordFollowupSent($waitingState->fresh());
        }

        $externalId = $this->resolveExternalId($dispatchResult->results);

        return ActionHandlerResult::success(
            externalId: $externalId,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<int, NotificationResult>  $results
     */
    private function resolveExternalId(array $results): ?string
    {
        foreach ($results as $result) {
            if ($result->success && filled($result->external_id)) {
                return $result->external_id;
            }
        }

        foreach ($results as $result) {
            if ($result->success) {
                return $result->channel === NotificationChannelType::WhatsApp
                    ? 'whatsapp-dispatched'
                    : $result->channel->value;
            }
        }

        return null;
    }
}
