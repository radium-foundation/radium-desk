<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceResolveActionService
{
    public function __construct(
        private readonly WorkspaceCloseActionService $closeActionService,
    ) {}

    /**
     * @deprecated Resolve has been replaced by Close. Kept for backward compatibility.
     */
    public function resolve(
        Incident $incident,
        User $actor,
        string $body,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        return $this->closeActionService->close(
            incident: $incident,
            actor: $actor,
            payload: ['body' => $body],
            requestContext: $requestContext,
            request: $request,
        );
    }

    public function validationFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
        array $payload = [],
    ): WorkspaceActionResponse {
        return $this->closeActionService->validationFailure(
            $incident,
            $requestContext,
            $exception,
            $payload,
        );
    }
}
