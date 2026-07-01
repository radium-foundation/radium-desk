<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\User;
use App\Services\Concerns\BuildsWorkspaceValidationFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceCloseActionService
{
    use BuildsWorkspaceValidationFailure;

    public function __construct(
        private readonly RemarkService $remarkService,
        private readonly ServiceCaseStatusService $statusService,
        private readonly ServiceCaseCloseRequirementService $closeRequirementService,
        private readonly ServiceCaseCloseExceptionService $closeExceptionService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    /**
     * @param  array{
     *     body: string,
     *     serial_number_unavailable?: bool,
     *     reference_number_unavailable?: bool,
     *     exception_reason?: string|null,
     *     exception_reason_custom?: string|null,
     *     notify_whatsapp?: bool,
     *     notify_email?: bool,
     * }  $payload
     */
    public function close(
        Incident $incident,
        User $actor,
        array $payload,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        try {
            $freshIncident = $this->executeClose($incident, $actor, $payload, $request);
        } catch (ValidationException $exception) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                $exception,
                $payload,
            );
        }

        return $this->buildSuccessResponse($freshIncident, $requestContext, $actor);
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
        $message = $this->firstValidationMessageFromException($exception);
        $fragmentHtml = $this->refreshRenderer->renderActionFragment(
            $incident,
            $requestContext,
            WorkspaceActionType::Close,
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

    /**
     * @param  array{
     *     body: string,
     *     serial_number_unavailable?: bool,
     *     reference_number_unavailable?: bool,
     *     exception_reason?: string|null,
     *     exception_reason_custom?: string|null,
     *     notify_whatsapp?: bool,
     *     notify_email?: bool,
     * }  $payload
     */
    private function executeClose(
        Incident $incident,
        User $actor,
        array $payload,
        ?Request $request = null,
    ): Incident {
        $serialUnavailable = (bool) ($payload['serial_number_unavailable'] ?? false);
        $referenceUnavailable = (bool) ($payload['reference_number_unavailable'] ?? false);

        $this->closeRequirementService->ensureRequirementsMet(
            $incident,
            $serialUnavailable,
            $referenceUnavailable,
        );

        if ($serialUnavailable || $referenceUnavailable) {
            if (! filled($payload['exception_reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'exception_reason' => 'Select a reason when recording an exception.',
                ]);
            }
        }

        return DB::transaction(function () use (
            $incident,
            $actor,
            $payload,
            $request,
            $serialUnavailable,
            $referenceUnavailable,
        ): Incident {
            if ($serialUnavailable || $referenceUnavailable) {
                $reason = ServiceCaseCloseExceptionReason::from((string) $payload['exception_reason']);

                $this->closeExceptionService->create(
                    incident: $incident,
                    actor: $actor,
                    serialNumberUnavailable: $serialUnavailable,
                    referenceNumberUnavailable: $referenceUnavailable,
                    reason: $reason,
                    reasonCustom: $payload['exception_reason_custom'] ?? null,
                    notifyWhatsapp: (bool) ($payload['notify_whatsapp'] ?? false),
                    notifyEmail: (bool) ($payload['notify_email'] ?? false),
                    request: $request,
                );
            }

            $this->remarkService->createForRemarkable(
                remarkable: $incident,
                actor: $actor,
                body: (string) $payload['body'],
                request: $request,
            );

            return $this->statusService->updateStatus(
                incident: $incident,
                status: IncidentStatus::Closed,
                actor: $actor,
            );
        });
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

        $refresh = $this->refreshRenderer->buildRefreshPayload(
            $effects,
            WorkspaceComponent::Action,
            $incident,
            $actor,
        );

        $message = 'Service case closed.';

        return WorkspaceActionResponseBuilder::make('action', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withRefresh($refresh)
            ->build();
    }
}
