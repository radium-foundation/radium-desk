<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\User;
use App\Services\Concerns\BuildsWorkspaceValidationFailure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceReopenActionService
{
    use BuildsWorkspaceValidationFailure;

    public function __construct(
        private readonly ServiceCaseReopenService $reopenService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    public function reopen(
        Incident $incident,
        User $actor,
        string $body,
        WorkspaceRequestContext $requestContext,
        ?User $assignee = null,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        try {
            $freshIncident = $this->reopenService->reopen(
                incident: $incident,
                actor: $actor,
                body: $body,
                assignee: $assignee,
                request: $request,
            );
        } catch (ValidationException $exception) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                $exception,
                [
                    'body' => $body,
                    'assigned_to_user_id' => $assignee?->id,
                ],
            );
        }

        return $this->buildSuccessResponse($freshIncident, $requestContext, $actor);
    }

    public function validationFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
        array $payload = [],
    ): WorkspaceActionResponse {
        $message = $this->firstValidationMessageFromException($exception);
        $fragmentHtml = $this->refreshRenderer->renderActionFragment(
            $incident,
            $requestContext,
            WorkspaceActionType::Reopen,
            $payload,
        );

        return WorkspaceActionResponseBuilder::make('action', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->withValidationFragment('action', $fragmentHtml)
            ->build();
    }

    private function buildSuccessResponse(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        User $actor,
    ): WorkspaceActionResponse {
        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::Action,
            $incident,
        );

        $refresh = $this->refreshRenderer->buildRefreshPayload(
            $effects,
            WorkspaceComponent::Action,
            $incident,
            $actor,
        );

        $message = 'Service case reopened.';

        return WorkspaceActionResponseBuilder::make('action', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withRefresh($refresh)
            ->build();
    }
}
