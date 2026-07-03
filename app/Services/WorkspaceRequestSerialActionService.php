<?php

namespace App\Services;

use App\Data\NotificationMessage;
use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\NotificationType;
use App\Enums\WaitingReason;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\User;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\Notifications\NotificationChannelAvailabilityService;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class WorkspaceRequestSerialActionService
{
    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly RequestSerialNumberEligibilityService $eligibilityService,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly NotificationDeliverySummaryFormatter $deliverySummaryFormatter,
        private readonly NotificationChannelAvailabilityService $channelAvailabilityService,
    ) {}

    public function send(
        Incident $incident,
        User $actor,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        if (! $actor->can('update', $incident)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $reason = $this->eligibilityService->ineligibilityReason($incident);

        if ($reason !== null) {
            return WorkspaceActionResponseBuilder::make('request-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($reason)
                ->withToast($reason, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $incident->loadMissing('order');
        $channels = $this->channelAvailabilityService->forRequestSerialNumber($incident->order);
        $channelBlockReason = $this->channelAvailabilityService->unavailableReason($channels);

        if ($channelBlockReason !== null) {
            $message = 'Notification failed.'."\n".$channelBlockReason;

            return WorkspaceActionResponseBuilder::make('request-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($message)
                ->withToast($message, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $dispatchResult = $this->notificationDispatcher->send(
            NotificationType::RequestSerialNumber,
            new NotificationMessage(
                type: NotificationType::RequestSerialNumber,
                customer: $incident->order,
                incident: $incident,
                template: WhatsAppTemplate::RequestSerialNumber->value,
                metadata: [
                    'source' => 'customer360',
                    'trigger_source' => WhatsAppTemplateTriggerSource::Manual->value,
                ],
                actor: $actor,
                httpRequest: $request,
            ),
        );

        if (! $dispatchResult->success) {
            $message = $this->deliverySummaryFormatter->formatOperatorResult($dispatchResult);

            return WorkspaceActionResponseBuilder::make('request-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($message)
                ->withToast($message, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $waitingStateSuffix = 'Waiting state started.';

        if ($this->waitingStateService->activeFor($incident) === null) {
            $this->waitingStateService->start(
                incident: $incident,
                reason: WaitingReason::SerialNumber,
                actor: $actor,
            );
        } else {
            $waitingStateSuffix = 'Waiting state already active.';
        }

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            \App\Enums\WorkspaceComponent::RequestSerialNumber,
            $incident,
        );

        $message = $this->deliverySummaryFormatter->formatOperatorResult(
            $dispatchResult,
            $waitingStateSuffix,
        );
        $toastVariant = $this->resolveToastVariant($dispatchResult);

        return WorkspaceActionResponseBuilder::make('request-serial', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, $toastVariant)
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withExtensions([
                'refresh_customer360' => $requestContext->context === \App\Enums\WorkspaceContext::Customer,
            ])
            ->build();
    }

    private function resolveToastVariant(\App\Data\NotificationDispatchResult $dispatchResult): string
    {
        $hasFailure = collect($dispatchResult->results)->contains(
            fn (\App\Data\NotificationResult $result): bool => ! $result->isSkipped() && ! $result->success,
        );

        return $hasFailure ? 'warning' : 'success';
    }
}
