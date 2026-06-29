<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceResolveActionService
{
    public function __construct(
        private readonly ServiceCaseActionRemarkService $actionRemarkService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    public function resolve(
        Incident $incident,
        User $actor,
        string $body,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        try {
            $freshIncident = $this->actionRemarkService->execute(
                incident: $incident,
                actor: $actor,
                status: IncidentStatus::Resolved,
                body: $body,
                request: $request,
            );
        } catch (ValidationException $exception) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $body,
            );
        }

        return $this->buildSuccessResponse($freshIncident, $requestContext, $actor);
    }

    public function validationFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
        ?string $body = null,
    ): WorkspaceActionResponse {
        $fragmentHtml = $this->refreshRenderer->renderResolveFragment(
            $incident,
            $requestContext,
            $body,
        );

        return WorkspaceActionResponseBuilder::make('resolve', $incident->id)
            ->forContext($requestContext->context)
            ->failure('The given data was invalid.')
            ->withToast('Please correct the highlighted fields.', 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->withValidationFragment('resolve', $fragmentHtml)
            ->build();
    }

    private function buildSuccessResponse(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        User $actor,
    ): WorkspaceActionResponse {
        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::Resolve,
            $incident,
        );

        $refresh = $this->refreshRenderer->buildRefreshPayload(
            $effects,
            WorkspaceComponent::Resolve,
            $incident,
            $actor,
        );

        $message = 'Service case resolved.';

        return WorkspaceActionResponseBuilder::make('resolve', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withRefresh($refresh)
            ->build();
    }
}
