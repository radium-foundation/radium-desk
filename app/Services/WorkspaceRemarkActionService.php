<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\User;
use App\Services\Concerns\BuildsWorkspaceValidationFailure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceRemarkActionService
{
    use BuildsWorkspaceValidationFailure;

    public function __construct(
        private readonly RemarkService $remarkService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    public function store(
        Incident $incident,
        string $body,
        User $actor,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        $this->remarkService->createForRemarkable(
            remarkable: $incident,
            actor: $actor,
            body: $body,
            request: $request,
        );

        return $this->buildSuccessResponse($incident, $requestContext, $actor);
    }

    public function validationFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
        ?string $body = null,
    ): WorkspaceActionResponse {
        $message = $this->firstValidationMessageFromException($exception);
        $fragmentHtml = $this->refreshRenderer->renderRemarkFragment(
            $incident,
            $requestContext,
            $body,
        );

        return WorkspaceActionResponseBuilder::make('remark', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->withValidationFragment('remark', $fragmentHtml)
            ->build();
    }

    private function buildSuccessResponse(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        User $actor,
    ): WorkspaceActionResponse {
        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::Remark,
            $incident,
        );

        $refresh = $this->refreshRenderer->buildRefreshPayload(
            $effects,
            WorkspaceComponent::Remark,
            $incident,
            $actor,
        );

        $message = 'Note saved.';

        return WorkspaceActionResponseBuilder::make('remark', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withRefresh($refresh)
            ->build();
    }
}
