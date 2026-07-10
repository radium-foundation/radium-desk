<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceLinkOrderRequest;
use App\Http\Requests\WorkspaceActionRequest;
use App\Http\Requests\WorkspaceAssignRequest;
use App\Http\Requests\WorkspaceCloseRequest;
use App\Http\Requests\WorkspaceRemarkRequest;
use App\Http\Requests\WorkspaceResolveRequest;
use App\Models\Incident;
use App\Models\User;
use App\Enums\WorkspaceActionType;
use App\Services\WorkspaceActionDialogService;
use App\Services\WorkspaceAssignActionService;
use App\Services\WorkspaceCloseActionService;
use App\Services\WorkspaceContextResolver;
use App\Services\WorkspaceRemarkActionService;
use App\Services\WorkspaceLinkOrderActionService;
use App\Services\WorkspaceRequestCorrectSerialActionService;
use App\Services\WorkspaceRequestSerialActionService;
use App\Services\WorkspaceCustomerNotRespondingActionService;
use App\Services\WorkspaceResolveActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceActionController extends Controller
{
    public function __construct(
        private readonly WorkspaceActionDialogService $actionDialogService,
        private readonly WorkspaceAssignActionService $assignActionService,
        private readonly WorkspaceRemarkActionService $remarkActionService,
        private readonly WorkspaceResolveActionService $resolveActionService,
        private readonly WorkspaceCloseActionService $closeActionService,
        private readonly WorkspaceRequestSerialActionService $requestSerialActionService,
        private readonly WorkspaceRequestCorrectSerialActionService $requestCorrectSerialActionService,
        private readonly WorkspaceCustomerNotRespondingActionService $customerNotRespondingActionService,
        private readonly WorkspaceLinkOrderActionService $linkOrderActionService,
        private readonly WorkspaceContextResolver $contextResolver,
    ) {}

    public function action(WorkspaceActionRequest $request, Incident $incident): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request, $incident);
        $actionType = WorkspaceActionType::from($request->string('action_type')->toString());

        $response = $this->actionDialogService->execute(
            incident: $incident,
            actor: $request->user(),
            actionType: $actionType,
            payload: $request->all(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }

    public function assign(WorkspaceAssignRequest $request, Incident $incident): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request, $incident);
        $assignee = User::query()->findOrFail($request->integer('assigned_to_user_id'));

        try {
            $response = $this->assignActionService->assign(
                incident: $incident,
                assignee: $assignee,
                actor: $request->user(),
                body: $request->string('body')->toString(),
                requestContext: $requestContext,
                request: $request,
            );
        } catch (ValidationException $exception) {
            $response = $this->assignActionService->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $request->all(),
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
            payload: ['body' => $request->string('body')->toString()],
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }

    public function requestSerial(Request $request, Incident $incident): JsonResponse
    {
        $this->authorize('update', $incident);

        $requestContext = $this->contextResolver->resolve($request, $incident);

        $response = $this->requestSerialActionService->send(
            incident: $incident,
            actor: $request->user(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }

    public function requestCorrectSerial(Request $request, Incident $incident): JsonResponse
    {
        $this->authorize('update', $incident);

        $requestContext = $this->contextResolver->resolve($request, $incident);

        $response = $this->requestCorrectSerialActionService->send(
            incident: $incident,
            actor: $request->user(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }

    public function customerNotResponding(Request $request, Incident $incident): JsonResponse
    {
        $this->authorize('update', $incident);

        $requestContext = $this->contextResolver->resolve($request, $incident);

        $response = $this->customerNotRespondingActionService->send(
            incident: $incident,
            actor: $request->user(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }

    public function linkOrder(WorkspaceLinkOrderRequest $request, Incident $incident): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request, $incident);

        $response = $this->linkOrderActionService->link(
            incident: $incident,
            actor: $request->user(),
            payload: $request->validated(),
            requestContext: $requestContext,
            request: $request,
        );

        return $response->toJsonResponse($response->success ? 200 : 422);
    }
}
