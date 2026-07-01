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
     *     serial_exception_reason?: string|null,
     *     serial_exception_reason_custom?: string|null,
     *     reference_exception_reason?: string|null,
     *     reference_exception_reason_custom?: string|null,
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
     *     serial_exception_reason?: string|null,
     *     serial_exception_reason_custom?: string|null,
     *     reference_exception_reason?: string|null,
     *     reference_exception_reason_custom?: string|null,
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

        $this->validateExceptionReasons($payload, $serialUnavailable, $referenceUnavailable);

        return DB::transaction(function () use (
            $incident,
            $actor,
            $payload,
            $request,
            $serialUnavailable,
            $referenceUnavailable,
        ): Incident {
            $notifyWhatsapp = (bool) ($payload['notify_whatsapp'] ?? false);
            $notifyEmail = (bool) ($payload['notify_email'] ?? false);

            if ($serialUnavailable) {
                $this->closeExceptionService->createSerialException(
                    incident: $incident,
                    actor: $actor,
                    reason: ServiceCaseCloseExceptionReason::from((string) $payload['serial_exception_reason']),
                    reasonCustom: $payload['serial_exception_reason_custom'] ?? null,
                    notifyWhatsapp: $notifyWhatsapp,
                    notifyEmail: $notifyEmail,
                    request: $request,
                );
            }

            if ($referenceUnavailable) {
                $this->closeExceptionService->createReferenceException(
                    incident: $incident,
                    actor: $actor,
                    reason: ServiceCaseCloseExceptionReason::from((string) $payload['reference_exception_reason']),
                    reasonCustom: $payload['reference_exception_reason_custom'] ?? null,
                    notifyWhatsapp: $notifyWhatsapp,
                    notifyEmail: $notifyEmail,
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

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function validateExceptionReasons(
        array $payload,
        bool $serialUnavailable,
        bool $referenceUnavailable,
    ): void {
        $messages = [];

        if ($serialUnavailable && ! filled($payload['serial_exception_reason'] ?? null)) {
            $messages['serial_exception_reason'] = 'Select a reason when serial number is unavailable.';
        }

        if ($referenceUnavailable && ! filled($payload['reference_exception_reason'] ?? null)) {
            $messages['reference_exception_reason'] = 'Select a reason when reference number is unavailable.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
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
