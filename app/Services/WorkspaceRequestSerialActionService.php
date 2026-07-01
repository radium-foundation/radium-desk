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
            $message = $dispatchResult->message ?? 'Unable to send WhatsApp template.';

            return WorkspaceActionResponseBuilder::make('request-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($message)
                ->withToast($message, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $this->waitingStateService->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $actor,
        );

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            \App\Enums\WorkspaceComponent::RequestSerialNumber,
            $incident,
        );

        $message = 'WhatsApp template request sent.';

        return WorkspaceActionResponseBuilder::make('request-serial', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withExtensions([
                'refresh_customer360' => $requestContext->context === \App\Enums\WorkspaceContext::Customer,
            ])
            ->build();
    }
}
