<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\User;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\Interakt\WhatsAppAutomationDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class WorkspaceRequestSerialActionService
{
    public function __construct(
        private readonly WhatsAppAutomationDispatcher $automationDispatcher,
        private readonly RequestSerialNumberEligibilityService $eligibilityService,
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

        $result = $this->automationDispatcher->dispatch(
            template: WhatsAppTemplate::RequestSerialNumber,
            incident: $incident,
            triggerSource: WhatsAppTemplateTriggerSource::Manual,
            actor: $actor,
            context: [
                'source' => 'customer360',
            ],
            request: $request,
        );

        if (! $result->success) {
            $message = $result->message ?? 'Unable to send WhatsApp template.';

            return WorkspaceActionResponseBuilder::make('request-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($message)
                ->withToast($message, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

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
