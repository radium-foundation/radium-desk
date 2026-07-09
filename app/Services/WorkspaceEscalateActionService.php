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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceEscalateActionService
{
    use BuildsWorkspaceValidationFailure;

    public function __construct(
        private readonly ServiceCaseEscalationService $escalationService,
        private readonly RemarkService $remarkService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    public function escalate(
        Incident $incident,
        User $actor,
        string $body,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        try {
            $freshIncident = DB::transaction(function () use ($incident, $actor, $body, $request): Incident {
                $this->remarkService->createForRemarkable(
                    remarkable: $incident,
                    actor: $actor,
                    body: $body,
                    request: $request,
                );

                return $this->escalationService->escalate(
                    incident: $incident,
                    actor: $actor,
                    reason: $body,
                );
            });
        } catch (ValidationException $exception) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                $exception,
                ['body' => $body],
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
            WorkspaceActionType::Escalate,
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

        $assigneeName = $incident->assignee?->firstName() ?? 'escalation specialist';
        $message = "Service case escalated to {$assigneeName}.";

        return WorkspaceActionResponseBuilder::make('action', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withRefresh($refresh)
            ->build();
    }
}
