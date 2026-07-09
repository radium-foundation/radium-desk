<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceActionType;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceActionDialogService
{
    public function __construct(
        private readonly WorkspaceAssignActionService $assignActionService,
        private readonly WorkspaceCloseActionService $closeActionService,
        private readonly WorkspaceReopenActionService $reopenActionService,
        private readonly WorkspaceEscalateActionService $escalateActionService,
        private readonly ServiceCaseEscalationService $escalationService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        Incident $incident,
        User $actor,
        WorkspaceActionType $actionType,
        array $payload,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        return match ($actionType) {
            WorkspaceActionType::Assign => $this->assignActionService->assign(
                incident: $incident,
                assignee: User::query()->findOrFail((int) ($payload['assigned_to_user_id'] ?? 0)),
                actor: $actor,
                body: (string) ($payload['body'] ?? ''),
                requestContext: $requestContext,
                request: $request,
            ),
            WorkspaceActionType::Close => $this->closeActionService->close(
                incident: $incident,
                actor: $actor,
                payload: $payload,
                requestContext: $requestContext,
                request: $request,
            ),
            WorkspaceActionType::Reopen => $this->reopenActionService->reopen(
                incident: $incident,
                actor: $actor,
                body: (string) ($payload['body'] ?? ''),
                requestContext: $requestContext,
                assignee: isset($payload['assigned_to_user_id']) && filled($payload['assigned_to_user_id'])
                    ? User::query()->find((int) $payload['assigned_to_user_id'])
                    : null,
                request: $request,
            ),
            WorkspaceActionType::Escalate => $this->escalateActionService->escalate(
                incident: $incident,
                actor: $actor,
                body: (string) ($payload['body'] ?? ''),
                requestContext: $requestContext,
                request: $request,
            ),
        };
    }

    public function validationFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
        WorkspaceActionType $actionType,
        array $payload = [],
    ): WorkspaceActionResponse {
        return match ($actionType) {
            WorkspaceActionType::Assign => $this->assignActionService->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $payload,
            ),
            WorkspaceActionType::Close => $this->closeActionService->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $payload,
            ),
            WorkspaceActionType::Reopen => $this->reopenActionService->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $payload,
            ),
            WorkspaceActionType::Escalate => $this->escalateActionService->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $payload,
            ),
        };
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(Incident $incident, User $user): array
    {
        $canUpdate = $user->can('update', $incident);
        $isClosed = $incident->status === IncidentStatus::Closed;

        return [
            'assign' => $user->can('reassign', $incident) && ! $isClosed,
            'close' => $canUpdate && ! $isClosed,
            'reopen' => $canUpdate && $isClosed,
            'escalate' => $this->escalationService->canEscalate($incident, $user),
        ];
    }
}
