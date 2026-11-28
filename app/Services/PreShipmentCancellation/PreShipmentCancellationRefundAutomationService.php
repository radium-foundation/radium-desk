<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\ApprovedRefundMethod;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundStatus;
use App\Jobs\PreShipmentCancellation\ProcessPreShipmentCancellationRefundAutomationJob;
use App\Models\PreShipmentCancellationRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundRequestService;
use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PreShipmentCancellationRefundAutomationService
{
    public const SUB_STATUS_LINK_FAILED = 'refund_auto_link_failed';

    public const SUB_STATUS_EXECUTION_FAILED = 'refund_auto_execution_failed';

    public function __construct(
        private readonly PreShipmentCancellationFeature $feature,
        private readonly PreShipmentCancellationRefundLinkService $linkService,
        private readonly RefundRequestService $refundRequests,
        private readonly PreShipmentCancellationFinancialReconciliationService $reconciliation,
    ) {}

    public function dispatchAfterCommit(PreShipmentCancellationRequest $case, int $actorUserId): void
    {
        if (! $this->feature->enabled() || ! $this->shouldSchedule($case->fresh() ?? $case)) {
            return;
        }

        ProcessPreShipmentCancellationRefundAutomationJob::dispatch($case->id, $actorUserId)
            ->afterResponse();
    }

    public function process(int $preShipmentCancellationRequestId, int $actorUserId): void
    {
        if (! $this->feature->enabled()) {
            return;
        }

        $actor = User::query()->find($actorUserId);
        if ($actor === null) {
            Log::warning('[preship-cancel-refund-automation] Skipped; actor missing.', [
                'pre_shipment_cancellation_request_id' => $preShipmentCancellationRequestId,
            ]);

            return;
        }

        $case = PreShipmentCancellationRequest::query()->find($preShipmentCancellationRequestId);
        if ($case === null || ! $this->isAutomationCandidate($case)) {
            return;
        }

        $httpRequest = Request::create('/', 'POST');

        try {
            $refund = $this->ensureLinkedRefund($actor, $case, $httpRequest);
            $case = $case->fresh() ?? $case;

            $this->clearAutomationFailureMarkers($case);

            if ($this->shouldAttemptWalletAutoExecution($case)) {
                $this->executeWalletRefundIfNeeded($actor, $case, $refund, $httpRequest);
            }
        } catch (ValidationException $exception) {
            $this->recordAutomationFailure($case, self::SUB_STATUS_LINK_FAILED, $exception);
            Log::warning('[preship-cancel-refund-automation] Refund link validation failed.', [
                'pre_shipment_cancellation_request_id' => $case->id,
                'messages' => $exception->errors(),
            ]);
        } catch (Throwable $exception) {
            $phase = ($case->fresh()?->refund_request_id !== null)
                ? self::SUB_STATUS_EXECUTION_FAILED
                : self::SUB_STATUS_LINK_FAILED;
            $this->recordAutomationFailure($case, $phase, $exception);
            Log::error('[preship-cancel-refund-automation] Automation failed; cancellation remains committed.', [
                'pre_shipment_cancellation_request_id' => $case->id,
                'message' => $exception->getMessage(),
            ]);
        }

        $fresh = PreShipmentCancellationRequest::query()->find($case->id);
        if ($fresh !== null) {
            $this->reconciliation->syncAlreadyRefundedFromLinkedRefund($fresh);
        }
    }

    private function shouldSchedule(?PreShipmentCancellationRequest $case): bool
    {
        return $case !== null && $this->isAutomationCandidate($case);
    }

    private function isAutomationCandidate(PreShipmentCancellationRequest $case): bool
    {
        if ($case->split_tender) {
            return false;
        }

        if (in_array($case->status, [
            PreShipmentCancellationStatus::HeldExistingInvoice,
            PreShipmentCancellationStatus::HeldServiceCase,
            PreShipmentCancellationStatus::FinanceReview,
        ], true)) {
            return false;
        }

        if ($case->refund_request_id === null) {
            return $case->status === PreShipmentCancellationStatus::FinanceApproved;
        }

        return in_array($case->status, [
            PreShipmentCancellationStatus::RefundRequestLinked,
            PreShipmentCancellationStatus::Completed,
        ], true);
    }

    private function ensureLinkedRefund(
        User $actor,
        PreShipmentCancellationRequest $case,
        Request $httpRequest,
    ): RefundRequest {
        $case = $case->fresh() ?? $case;
        if ($case->refund_request_id !== null) {
            return RefundRequest::query()->findOrFail($case->refund_request_id);
        }

        return $this->linkService->approveAndLink($actor, $case, $httpRequest);
    }

    private function shouldAttemptWalletAutoExecution(PreShipmentCancellationRequest $case): bool
    {
        $metadata = PreShipmentCancellationOperatorMetadata::decode($case->blocker_reason);
        if (($metadata['refund_preference'] ?? null) !== 'wallet') {
            return false;
        }

        if (! (bool) config('refunds.wallet_auto_approval.enabled', false)) {
            return false;
        }

        return (bool) config('central_wallet.wallet_refund.desk_first_enabled', false);
    }

    private function executeWalletRefundIfNeeded(
        User $actor,
        PreShipmentCancellationRequest $case,
        RefundRequest $refund,
        Request $httpRequest,
    ): void {
        $refund = $refund->fresh() ?? $refund;

        if (in_array($refund->status, [RefundStatus::Completed, RefundStatus::Closed], true)) {
            return;
        }

        if ($refund->status === RefundStatus::Pending) {
            $refund = $this->refundRequests->approve($refund, $actor, [
                'approved_refund_method' => ApprovedRefundMethod::Wallet->value,
                'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                'refund_amount' => (float) $case->requested_refund_amount,
            ], $httpRequest);
            $refund = $refund->fresh() ?? $refund;
        }

        if ($refund->status !== RefundStatus::PendingExecution) {
            return;
        }

        try {
            $this->refundRequests->complete(
                refund: $refund,
                user: $actor,
                data: [],
                request: $httpRequest,
            );
        } catch (Throwable $exception) {
            $this->recordAutomationFailure($case, self::SUB_STATUS_EXECUTION_FAILED, $exception);

            throw $exception;
        }

        $this->clearAutomationFailureMarkers($case->fresh() ?? $case);
    }

    private function clearAutomationFailureMarkers(PreShipmentCancellationRequest $case): void
    {
        if ($case->sub_status === null
            && $case->execution_hold_reason === null) {
            return;
        }

        if (! in_array($case->sub_status, [
            self::SUB_STATUS_LINK_FAILED,
            self::SUB_STATUS_EXECUTION_FAILED,
        ], true)) {
            return;
        }

        $case->forceFill([
            'sub_status' => null,
            'execution_hold_reason' => $case->execution_hold ? $case->execution_hold_reason : null,
        ])->save();
    }

    private function recordAutomationFailure(
        PreShipmentCancellationRequest $case,
        string $subStatus,
        Throwable $exception,
    ): void {
        $case = $case->fresh() ?? $case;
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = 'Refund automation failed.';
        }
        $message = mb_substr($message, 0, 500);

        $case->forceFill([
            'sub_status' => $subStatus,
            'execution_hold_reason' => $message,
        ])->save();
    }
}
