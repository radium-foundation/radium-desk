<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceComponent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WorkspaceBatchTransactionActionService
{
    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    /**
     * @param  list<int>  $incidentIds
     */
    public function assign(
        array $incidentIds,
        string $transactionId,
        User $actor,
        WorkspaceRequestContext $requestContext,
    ): WorkspaceActionResponse {
        $result = $this->orderTransactionService->assignTransactionIdToIncidents(
            incidentIds: $incidentIds,
            transactionId: $transactionId,
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
        $fragmentHtml = $this->refreshRenderer->renderBatchTransactionFragment(
            $incidentIds,
            $requestContext,
        );

        $anchorIncidentId = $incidentIds[0] ?? 0;

        return WorkspaceActionResponseBuilder::make('batch-transaction', $anchorIncidentId)
            ->forContext($requestContext->context)
            ->failure('The given data was invalid.')
            ->withToast('Please correct the highlighted fields.', 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->withValidationFragment('batch-transaction', $fragmentHtml)
            ->build();
    }

    /**
     * @param  array{
     *     count: int,
     *     transaction_id: string,
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
        $transactionId = $result['transaction_id'];
        $anchorIncidentId = $result['succeeded_incident_ids'][0]
            ?? $result['failed_incidents'][0]['incident_id']
            ?? 0;

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::BatchTransaction,
        );

        $refresh = $this->refreshRenderer->buildBatchRefreshPayload(
            $effects,
            $result,
            $actor,
        );

        if ($succeededCount > 0 && $failedCount === 0) {
            $message = "Transaction {$transactionId} applied to {$succeededCount} service "
                .($succeededCount === 1 ? 'case' : 'cases').'.';
            $toastVariant = 'success';
            $isSuccess = true;
        } elseif ($succeededCount > 0) {
            $message = "Transaction {$transactionId} applied to {$succeededCount} of "
                .($succeededCount + $failedCount).' selected service cases.';
            $toastVariant = 'warning';
            $isSuccess = true;
        } else {
            $message = 'No transaction IDs could be assigned.';
            $toastVariant = 'danger';
            $isSuccess = false;
        }

        $builder = WorkspaceActionResponseBuilder::make('batch-transaction', $anchorIncidentId)
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
