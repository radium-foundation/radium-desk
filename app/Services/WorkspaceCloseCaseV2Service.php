<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\WorkspaceComponent;
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
        private readonly CustomerNotRespondingCloseService $customerNotRespondingCloseService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
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

        $reason = ServiceCaseCloseReasonForClosing::from((string) $payload['reason_for_closing']);

        if ($reason === ServiceCaseCloseReasonForClosing::CustomerNotResponding) {
            try {
                $payload = $this->prepareCustomerNotRespondingClose($incident, $actor, $payload, $request);
            } catch (ValidationException $exception) {
                return $this->closeActionService->validationFailure(
                    $incident,
                    $requestContext,
                    $exception,
                    $payload,
                );
            }
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
            $freshIncident = $incident->fresh() ?? $incident;

            $this->outcomeService->store(
                incident: $freshIncident,
                actor: $actor,
                outcomeData: $outcomeData,
                closedAt: $freshIncident->updated_at,
            );

            return $this->withRefreshedTimeline($response, $freshIncident, $actor, $requestContext);
        }

        return $response;
    }

    private function withRefreshedTimeline(
        WorkspaceActionResponse $response,
        Incident $incident,
        User $actor,
        WorkspaceRequestContext $requestContext,
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

        return new WorkspaceActionResponse(
            success: $response->success,
            message: $response->message,
            action: $response->action,
            incidentId: $response->incidentId,
            contractVersion: $response->contractVersion,
            toast: $response->toast,
            ui: $response->ui,
            refresh: $refresh,
            errors: $response->errors,
            meta: $response->meta,
            extensions: $response->extensions,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function prepareCustomerNotRespondingClose(
        Incident $incident,
        User $actor,
        array $payload,
        ?Request $request,
    ): array {
        $preference = ServiceCaseCloseNotificationPreference::from(
            (string) $payload['cnr_communication_preference'],
        );

        $dispatchResult = $this->customerNotRespondingCloseService->dispatchFinalReminder(
            incident: $incident,
            actor: $actor,
            preference: $preference,
            request: $request,
        );

        $this->customerNotRespondingCloseService->assertDispatchSucceeded($dispatchResult, $preference);

        $payload['notification_preference'] = $preference->value;
        $payload['communication_template'] = CustomerNotRespondingCloseService::TEMPLATE_KEY;
        $payload['communication_template_label'] = CustomerNotRespondingCloseService::TEMPLATE_LABEL;

        if ($incident->assigned_to_user_id !== null) {
            $payload['sticky_agent_user_id'] = $incident->assigned_to_user_id;
        }

        return $payload;
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
