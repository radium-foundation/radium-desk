<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceAssignRequest;
use App\Models\Incident;
use App\Models\User;
use App\Services\WorkspaceAssignActionService;
use App\Services\WorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class WorkspaceActionController extends Controller
{
    public function __construct(
        private readonly WorkspaceAssignActionService $assignActionService,
        private readonly WorkspaceContextResolver $contextResolver,
    ) {}

    public function assign(WorkspaceAssignRequest $request, Incident $incident): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request, $incident);
        $assignee = User::query()->findOrFail($request->integer('assigned_to_user_id'));

        try {
            $response = $this->assignActionService->assign(
                incident: $incident,
                assignee: $assignee,
                actor: $request->user(),
                requestContext: $requestContext,
            );
        } catch (ValidationException $exception) {
            $response = $this->assignActionService->validationFailure(
                $incident,
                $requestContext,
                $exception,
            );

            return $response->toJsonResponse(422);
        }

        return $response->toJsonResponse();
    }
}
