<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceBatchTransactionRequest;
use App\Services\WorkspaceBatchTransactionActionService;
use App\Services\WorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class DashboardWorkspaceActionController extends Controller
{
    public function __construct(
        private readonly WorkspaceBatchTransactionActionService $batchTransactionActionService,
        private readonly WorkspaceContextResolver $contextResolver,
    ) {}

    public function batchTransaction(WorkspaceBatchTransactionRequest $request): JsonResponse
    {
        $requestContext = $this->contextResolver->resolve($request);
        $incidentIds = array_map('intval', $request->input('incident_ids'));

        try {
            $response = $this->batchTransactionActionService->assign(
                incidentIds: $incidentIds,
                transactionId: $request->string('transaction_id')->trim()->toString(),
                actor: $request->user(),
                requestContext: $requestContext,
            );
        } catch (ValidationException $exception) {
            $response = $this->batchTransactionActionService->validationFailure(
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
