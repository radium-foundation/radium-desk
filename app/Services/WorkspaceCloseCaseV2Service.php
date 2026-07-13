<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceCloseCaseV2Service
{
    public function __construct(
        private readonly WorkspaceCloseCasePayloadAdapter $payloadAdapter,
        private readonly WorkspaceCloseActionService $closeActionService,
        private readonly ServiceCaseCloseOutcomeService $outcomeService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function close(
        Incident $incident,
        User $actor,
        array $payload,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        if (! $this->payloadAdapter->isV2Payload($payload)) {
            return $this->closeActionService->close(
                incident: $incident,
                actor: $actor,
                payload: $payload,
                requestContext: $requestContext,
                request: $request,
            );
        }

        try {
            $this->payloadAdapter->validateBeforeClose($incident, $actor, $payload);
        } catch (ValidationException $exception) {
            return $this->closeActionService->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $payload,
            );
        }

        $legacyPayload = $this->payloadAdapter->toLegacyPayload($incident, $payload);
        $outcomeData = $this->payloadAdapter->extractOutcomeData($payload);

        $response = $this->closeActionService->close(
            incident: $incident,
            actor: $actor,
            payload: $legacyPayload,
            requestContext: $requestContext,
            request: $request,
        );

        if ($response->success) {
            $this->outcomeService->store(
                incident: $incident->fresh() ?? $incident,
                actor: $actor,
                outcomeData: $outcomeData,
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
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
