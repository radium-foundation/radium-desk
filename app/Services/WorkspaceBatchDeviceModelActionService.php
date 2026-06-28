<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceComponent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WorkspaceBatchDeviceModelActionService
{
    public function __construct(
        private readonly OrderDeviceModelService $orderDeviceModelService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    /**
     * @param  list<int>  $incidentIds
     */
    public function assign(
        array $incidentIds,
        int $deviceModelId,
        User $actor,
        WorkspaceRequestContext $requestContext,
    ): WorkspaceActionResponse {
        $result = $this->orderDeviceModelService->assignDeviceModelToIncidents(
            incidentIds: $incidentIds,
            deviceModelId: $deviceModelId,
            actor: $actor,
        );

        return $this->buildSuccessResponse($result, $requestContext, $actor);
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function validationFailure(
        array $incidentIds,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
    ): WorkspaceActionResponse {
        $fragmentHtml = $this->refreshRenderer->renderBatchDeviceModelFragment(
            $incidentIds,
            $requestContext,
        );

        $anchorIncidentId = $incidentIds[0] ?? 0;

        return WorkspaceActionResponseBuilder::make('batch-device-model', $anchorIncidentId)
            ->forContext($requestContext->context)
            ->failure('The given data was invalid.')
            ->withToast('Please correct the highlighted fields.', 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->withValidationFragment('batch-device-model', $fragmentHtml)
            ->build();
    }

    /**
     * @param  array{
     *     count: int,
     *     device_model_id: int,
     *     device_model_name: string,
     *     rows: array<int, array{incident_id: int, html: string}>,
     *     succeeded_incident_ids: list<int>,
     *     failed_incidents: list<array{incident_id: int, message: string}>
     * }  $result
     */
    private function buildSuccessResponse(
        array $result,
        WorkspaceRequestContext $requestContext,
        User $actor,
    ): WorkspaceActionResponse {
        $succeededCount = count($result['succeeded_incident_ids']);
        $failedCount = count($result['failed_incidents']);
        $deviceModelName = $result['device_model_name'];
        $anchorIncidentId = $result['succeeded_incident_ids'][0]
            ?? $result['failed_incidents'][0]['incident_id']
            ?? 0;

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::BatchDeviceModel,
        );

        $refresh = $this->refreshRenderer->buildBatchRefreshPayload(
            $effects,
            $result,
            $actor,
        );

        if ($succeededCount > 0 && $failedCount === 0) {
            $message = "Device model {$deviceModelName} assigned to {$succeededCount} service "
                .($succeededCount === 1 ? 'case' : 'cases').'.';
            $toastVariant = 'success';
            $isSuccess = true;
        } elseif ($succeededCount > 0) {
            $message = "Device model {$deviceModelName} assigned to {$succeededCount} of "
                .($succeededCount + $failedCount).' selected service cases.';
            $toastVariant = 'warning';
            $isSuccess = true;
        } else {
            $message = 'No device models could be assigned.';
            $toastVariant = 'danger';
            $isSuccess = false;
        }

        $builder = WorkspaceActionResponseBuilder::make('batch-device-model', $anchorIncidentId)
            ->forContext($requestContext->context)
            ->withToast($message, $toastVariant)
            ->withUi(closeWorkspaceHost: $failedCount === 0)
            ->withRefresh($refresh)
            ->withExtensions([
                'succeeded_incident_ids' => $result['succeeded_incident_ids'],
                'failed_incidents' => $result['failed_incidents'],
            ]);

        if ($isSuccess) {
            return $builder->success($message)->build();
        }

        return $builder->failure($message)->build();
    }
}
