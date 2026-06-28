<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceBatchDeviceModelRequest;
use App\Services\WorkspaceBatchDeviceModelActionService;
use App\Services\WorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class DashboardWorkspaceDeviceModelController extends Controller
{
    public function __construct(
        private readonly WorkspaceBatchDeviceModelActionService $batchDeviceModelActionService,
        private readonly WorkspaceContextResolver $contextResolver,
    ) {}

    public function batchAssign(WorkspaceBatchDeviceModelRequest $request): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request);
        $incidentIds = array_map('intval', $request->input('incident_ids'));

        try {
            $response = $this->batchDeviceModelActionService->assign(
                incidentIds: $incidentIds,
                deviceModelId: $request->integer('device_model_id'),
                actor: $request->user(),
                requestContext: $requestContext,
            );
        } catch (ValidationException $exception) {
            $response = $this->batchDeviceModelActionService->validationFailure(
                $incidentIds,
                $requestContext,
                $exception,
            );

            return $response->toJsonResponse(422);
        }

        $status = $response->success ? 200 : 422;

        return $response->toJsonResponse($status);
    }
}
