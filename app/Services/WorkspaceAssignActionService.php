<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRefreshEffects;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\User;
use App\Services\Concerns\BuildsWorkspaceValidationFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceAssignActionService
{
    use BuildsWorkspaceValidationFailure;

    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly RemarkService $remarkService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    public function assign(
        Incident $incident,
        User $assignee,
        User $actor,
        string $body,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        try {
            $freshIncident = DB::transaction(function () use ($incident, $assignee, $actor, $body, $request): Incident {
                $this->remarkService->createForRemarkable(
                    remarkable: $incident,
                    actor: $actor,
                    body: $body,
                    request: $request,
                );

                return $this->assignmentService->reassign(
                    incident: $incident,
                    assignee: $assignee,
                    actor: $actor,
                );
            });
        } catch (ValidationException $exception) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                $exception,
                [
                    'body' => $body,
                    'assigned_to_user_id' => $assignee->id,
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
            WorkspaceActionType::Assign,
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

        if ($this->assignmentService->shouldRemoveFromAdminReadyQueue($incident)) {
            $effects = new WorkspaceRefreshEffects(
                refreshKpis: $effects->refreshKpis,
                removeRow: true,
                closeWorkspaceHost: $effects->closeWorkspaceHost,
            );
        }

        $refresh = $this->refreshRenderer->buildRefreshPayload(
            $effects,
            WorkspaceComponent::Action,
            $incident,
            $actor,
        );

        $assigneeName = $incident->assignee?->firstName() ?? 'admin';
        $message = "Service case assigned to {$assigneeName}.";

        return WorkspaceActionResponseBuilder::make('action', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withRefresh($refresh)
            ->build();
    }
}
