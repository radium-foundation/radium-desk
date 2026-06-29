<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceAssignRequest;
use App\Http\Requests\WorkspaceCloseRequest;
use App\Http\Requests\WorkspaceRemarkRequest;
use App\Http\Requests\WorkspaceResolveRequest;
use App\Models\Incident;
use App\Models\User;
use App\Services\WorkspaceAssignActionService;
use App\Services\WorkspaceCloseActionService;
use App\Services\WorkspaceContextResolver;
use App\Services\WorkspaceRemarkActionService;
use App\Services\WorkspaceResolveActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class WorkspaceActionController extends Controller
{
    public function __construct(
        private readonly WorkspaceAssignActionService $assignActionService,
        private readonly WorkspaceRemarkActionService $remarkActionService,
        private readonly WorkspaceResolveActionService $resolveActionService,
        private readonly WorkspaceCloseActionService $closeActionService,
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

    public function remark(WorkspaceRemarkRequest $request, Incident $incident): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request, $incident);

        $response = $this->remarkActionService->store(
            incident: $incident,
            body: $request->string('body')->toString(),
            actor: $request->user(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse();
    }

    public function resolve(WorkspaceResolveRequest $request, Incident $incident): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request, $incident);

        $response = $this->resolveActionService->resolve(
            incident: $incident,
            actor: $request->user(),
            body: $request->string('body')->toString(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }

    public function close(WorkspaceCloseRequest $request, Incident $incident): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request, $incident);

        $response = $this->closeActionService->close(
            incident: $incident,
            actor: $request->user(),
            body: $request->string('body')->toString(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }
}
