<?php

namespace App\Services;

use App\Data\Refunds\RefundCalculationResult;
use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundDifferenceReason;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Operations\TeamMemberActivityService;
use App\Services\Refunds\RefundExecutorResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundRequestService
{
    public function __construct(
        private readonly RefundReferenceService $referenceService,
        private readonly AuditLogService $auditLogService,
        private readonly RefundCalculationService $calculationService,
        private readonly RefundNotificationService $notificationService,
        private readonly RefundExecutorResolver $executorResolver,
        private readonly RefundCaseCloseService $caseCloseService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, Request $request): RefundRequest
    {
        $refund = DB::transaction(function () use ($user, $data): RefundRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($data['order_id']);

            $preferredMethod = CustomerPreferredRefundMethod::from(
                (string) ($data['customer_preferred_method'] ?? CustomerPreferredRefundMethod::Opm->value),
            );

            $profileKey = (string) ($data['deduction_profile_key'] ?? RefundDeductionProfile::FullRefund->value);

            $calculationInput = [
                'deduction_profile_key' => $profileKey,
                'cancellation_charges' => $data['cancellation_charges'] ?? null,
                'gst_on_cancellation' => $data['gst_on_cancellation'] ?? null,
                'other_deduction' => $data['other_deduction'] ?? null,
                'partial_difference_reason' => $data['partial_difference_reason'] ?? null,
                'partial_difference_notes' => $data['partial_difference_notes'] ?? null,
            ];

            if (array_key_exists('amount', $data) && $data['amount'] !== null && $data['amount'] !== '') {
                $calculationInput['refund_amount'] = $data['amount'];
            }

            $calculation = $this->calculationService->calculate($order, $calculationInput);
            $this->calculationService->assertValid($calculation);

            $channels = $this->normalizeChannels($data['communication_channels'] ?? ['email', 'whatsapp']);

            return RefundRequest::query()->create([
                'order_id' => $order->id,
                'incident_id' => $data['incident_id'] ?? null,
                'reference_no' => $this->referenceService->generate(),
                'amount' => $calculation->refundAmount,
                'refund_amount' => $calculation->refundAmount,
                'reason' => $data['reason'],
                'requester_remarks' => $data['remarks'],
                'customer_preferred_method' => $preferredMethod,
                'status' => RefundStatus::Pending,
                'total_paid_amount' => $calculation->totalPaidAmount,
                'already_refunded_amount' => $calculation->alreadyRefundedAmount,
                'maximum_refundable' => $calculation->maximumRefundable,
                'cancellation_charges' => $calculation->cancellationCharges,
                'gst_on_cancellation' => $calculation->gstOnCancellation,
                'other_deduction' => $calculation->otherDeduction,
                'total_deduction' => $calculation->totalDeduction,
                'deduction_profile_key' => $calculation->deductionProfileKey,
                'partial_difference_reason' => $calculation->partialDifferenceReason,
                'partial_difference_notes' => $calculation->partialDifferenceNotes,
                'communication_channels' => $channels,
                'deduction_snapshot' => $calculation->toArray(),
                'requested_by' => $user->id,
            ]);
        });

        $this->auditLogService->log(
            userId: $user->id,
            event: 'refund.requested',
            auditable: $refund,
            newValues: array_merge($this->financialSnapshot($refund), [
                'reference_no' => $refund->reference_no,
                'order_id' => $refund->order_id,
                'incident_id' => $refund->incident_id,
                'reason' => $refund->reason,
                'requester_remarks' => $refund->requester_remarks,
                'customer_preferred_method' => $refund->customer_preferred_method?->value,
                'communication_channels' => $refund->communication_channels,
                'status' => $refund->status->value,
            ]),
            request: $request,
        );

        // Preserve legacy audit event name for existing timeline consumers.
        $this->auditLogService->log(
            userId: $user->id,
            event: 'created',
            auditable: $refund,
            newValues: [
                'reference_no' => $refund->reference_no,
                'order_id' => $refund->order_id,
                'incident_id' => $refund->incident_id,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'requester_remarks' => $refund->requester_remarks,
                'status' => $refund->status->value,
            ],
            request: $request,
        );

        $this->notificationService->notifyApproversOfSubmission($refund->fresh(['requester']) ?? $refund);

        return $refund;
    }

    /**
     * Approve a pending refund and move it to pending execution.
     *
     * @param  array<string, mixed>  $data
     */
    public function approve(
        RefundRequest $refund,
        User $user,
        array $data,
        Request $request,
    ): RefundRequest {
        return DB::transaction(function () use ($refund, $user, $data, $request): RefundRequest {
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($refund->id);
            $this->ensurePendingApproval($locked);

            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            $method = ApprovedRefundMethod::from((string) $data['approved_refund_method']);

            $calculation = $this->calculationService->calculate($order, [
                'deduction_profile_key' => $data['deduction_profile_key']
                    ?? $locked->deduction_profile_key?->value
                    ?? RefundDeductionProfile::Custom->value,
                'cancellation_charges' => array_key_exists('cancellation_charges', $data)
                    ? $data['cancellation_charges']
                    : null,
                'gst_on_cancellation' => array_key_exists('gst_on_cancellation', $data)
                    ? $data['gst_on_cancellation']
                    : null,
                'other_deduction' => array_key_exists('other_deduction', $data)
                    ? $data['other_deduction']
                    : null,
                'refund_amount' => array_key_exists('refund_amount', $data)
                    ? $data['refund_amount']
                    : (array_key_exists('amount', $data) ? $data['amount'] : null),
                'partial_difference_reason' => $data['partial_difference_reason']
                    ?? $locked->partial_difference_reason?->value,
                'partial_difference_notes' => $data['partial_difference_notes']
                    ?? $locked->partial_difference_notes,
            ], $locked);

            if ($calculation->partialDifferenceReason === null
                && $calculation->refundAmount < $calculation->maximumRefundable - 0.001) {
                $inferredReason = match (true) {
                    $calculation->otherDeduction > 0
                        && $calculation->cancellationCharges <= 0 => RefundDifferenceReason::EngineerVisit->value,
                    $calculation->totalDeduction > 0 => RefundDifferenceReason::CancellationCharges->value,
                    default => RefundDifferenceReason::PartialRefund->value,
                };

                $calculation = new RefundCalculationResult(
                    totalPaidAmount: $calculation->totalPaidAmount,
                    alreadyRefundedAmount: $calculation->alreadyRefundedAmount,
                    maximumRefundable: $calculation->maximumRefundable,
                    cancellationCharges: $calculation->cancellationCharges,
                    gstOnCancellation: $calculation->gstOnCancellation,
                    otherDeduction: $calculation->otherDeduction,
                    totalDeduction: $calculation->totalDeduction,
                    refundAmount: $calculation->refundAmount,
                    deductionProfileKey: $calculation->deductionProfileKey,
                    partialDifferenceReason: $inferredReason,
                    partialDifferenceNotes: $calculation->partialDifferenceNotes,
                );
            }

            $this->calculationService->assertValid($calculation);

            $oldValues = $this->reviewSnapshot($locked);

            $locked->update([
                'status' => RefundStatus::PendingExecution,
                'approved_refund_method' => $method,
                'amount' => $calculation->refundAmount,
                'refund_amount' => $calculation->refundAmount,
                'total_paid_amount' => $calculation->totalPaidAmount,
                'already_refunded_amount' => $calculation->alreadyRefundedAmount,
                'maximum_refundable' => $calculation->maximumRefundable,
                'cancellation_charges' => $calculation->cancellationCharges,
                'gst_on_cancellation' => $calculation->gstOnCancellation,
                'other_deduction' => $calculation->otherDeduction,
                'total_deduction' => $calculation->totalDeduction,
                'deduction_profile_key' => $calculation->deductionProfileKey,
                'partial_difference_reason' => $calculation->partialDifferenceReason,
                'partial_difference_notes' => $calculation->partialDifferenceNotes,
                'deduction_snapshot' => $calculation->toArray(),
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $fresh = $locked->fresh();

            $this->auditLogService->log(
                userId: $user->id,
                event: 'refund.approved',
                auditable: $fresh,
                oldValues: $oldValues,
                newValues: array_merge($this->reviewSnapshot($fresh), $this->financialSnapshot($fresh), [
                    'approved_refund_method' => $method->value,
                    'reference_no' => $fresh->reference_no,
                ]),
                request: $request,
            );

            $this->auditLogService->log(
                userId: $user->id,
                event: 'refund.execution_started',
                auditable: $fresh,
                newValues: [
                    'status' => $fresh->status->value,
                    'approved_refund_method' => $method->value,
                    'reference_no' => $fresh->reference_no,
                ],
                request: $request,
            );

            // Legacy event for existing consumers.
            $this->auditLogService->log(
                userId: $user->id,
                event: 'approved',
                auditable: $fresh,
                oldValues: $oldValues,
                newValues: $this->reviewSnapshot($fresh),
                request: $request,
            );

            app(TeamMemberActivityService::class)
                ->recordCaseAction($user);

            return $fresh;
        });
    }

    public function reject(
        RefundRequest $refund,
        User $user,
        string $reviewNotes,
        Request $request,
    ): RefundRequest {
        $updated = DB::transaction(function () use ($refund, $user, $reviewNotes, $request): RefundRequest {
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($refund->id);
            $this->ensurePendingApproval($locked);

            $oldValues = $this->reviewSnapshot($locked);

            $locked->update([
                'status' => RefundStatus::Rejected,
                'review_notes' => $reviewNotes,
                'reject_reason' => $reviewNotes,
                'refund_transaction_id' => null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $fresh = $locked->fresh();

            $this->auditLogService->log(
                userId: $user->id,
                event: 'refund.rejected',
                auditable: $fresh,
                oldValues: $oldValues,
                newValues: array_merge($this->reviewSnapshot($fresh), [
                    'reject_reason' => $fresh->reject_reason,
                    'reference_no' => $fresh->reference_no,
                ]),
                request: $request,
            );

            $this->auditLogService->log(
                userId: $user->id,
                event: 'rejected',
                auditable: $fresh,
                oldValues: $oldValues,
                newValues: $this->reviewSnapshot($fresh),
                request: $request,
            );

            app(TeamMemberActivityService::class)
                ->recordCaseAction($user);

            return $fresh;
        });

        $this->notificationService->notifyRequesterOfDecision(
            $updated->fresh(['requester']) ?? $updated,
            'rejected',
        );

        return $updated;
    }

    /**
     * @param  array{
     *     execution_reference_no?: string|null,
     *     execution_transaction_id?: string|null,
     *     execution_remarks?: string|null,
     * }  $data
     */
    public function complete(
        RefundRequest $refund,
        User $user,
        array $data,
        Request $request,
    ): RefundRequest {
        $completed = DB::transaction(function () use ($refund, $user, $data, $request): RefundRequest {
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== RefundStatus::PendingExecution) {
                throw ValidationException::withMessages([
                    'refund' => 'Only refunds pending execution can be completed.',
                ]);
            }

            $execution = $this->executorResolver->execute($locked, $user, [
                'reference_number' => $data['execution_reference_no'] ?? null,
                'transaction_id' => $data['execution_transaction_id'] ?? null,
                'remarks' => $data['execution_remarks'] ?? null,
            ]);

            $oldValues = $this->executionSnapshot($locked);

            $locked->update([
                'status' => RefundStatus::Completed,
                'execution_reference_no' => $execution['reference_number'],
                'execution_transaction_id' => $execution['transaction_id'],
                'execution_remarks' => $execution['remarks'],
                'refund_transaction_id' => $execution['transaction_id'] ?? $locked->refund_transaction_id,
                'executed_by' => $user->id,
                'executed_at' => now(),
            ]);

            $fresh = $locked->fresh();

            $this->auditLogService->log(
                userId: $user->id,
                event: 'refund.completed',
                auditable: $fresh,
                oldValues: $oldValues,
                newValues: array_merge($this->executionSnapshot($fresh), [
                    'provider' => $execution['provider'],
                    'reference_no' => $fresh->reference_no,
                ]),
                request: $request,
            );

            app(TeamMemberActivityService::class)
                ->recordCaseAction($user);

            return $fresh;
        });

        $customerNotified = $this->notificationService->notifyCustomer(
            refund: $completed,
            actor: $user,
            channels: $completed->communication_channels,
            request: $request,
        );

        if ($customerNotified === false) {
            return $completed->fresh() ?? $completed;
        }

        $this->notificationService->notifyRequesterOfDecision(
            $completed->fresh(['requester']) ?? $completed,
            'completed',
        );

        $this->caseCloseService->closeLinkedCase($completed, $user, $request);

        return $completed->fresh() ?? $completed;
    }

    public function delete(RefundRequest $refund, User $user, Request $request): void
    {
        DB::transaction(function () use ($refund, $user, $request): void {
            $this->auditLogService->log(
                userId: $user->id,
                event: 'deleted',
                auditable: $refund,
                oldValues: [
                    'reference_no' => $refund->reference_no,
                    'status' => $refund->status->value,
                    'order_id' => $refund->order_id,
                ],
                request: $request,
            );

            $refund->delete();
        });
    }

    public function calculationPreview(Order $order, array $input = [], ?RefundRequest $exclude = null): RefundCalculationResult
    {
        return $this->calculationService->calculate($order, $input, $exclude);
    }

    private function ensurePendingApproval(RefundRequest $refund): void
    {
        if ($refund->status !== RefundStatus::Pending) {
            throw ValidationException::withMessages([
                'refund' => 'Only pending refund requests can be reviewed.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeChannels(mixed $channels): array
    {
        if (! is_array($channels)) {
            return ['email', 'whatsapp'];
        }

        $normalized = [];

        foreach ($channels as $channel) {
            $value = strtolower(trim((string) $channel));
            if (in_array($value, ['email', 'whatsapp'], true)) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewSnapshot(RefundRequest $refund): array
    {
        return [
            'status' => $refund->status->value,
            'review_notes' => $refund->review_notes,
            'reject_reason' => $refund->reject_reason,
            'refund_transaction_id' => $refund->refund_transaction_id,
            'approved_refund_method' => $refund->approved_refund_method?->value,
            'reviewed_by' => $refund->reviewed_by,
            'reviewed_at' => $refund->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executionSnapshot(RefundRequest $refund): array
    {
        return [
            'status' => $refund->status->value,
            'execution_reference_no' => $refund->execution_reference_no,
            'execution_transaction_id' => $refund->execution_transaction_id,
            'execution_remarks' => $refund->execution_remarks,
            'executed_by' => $refund->executed_by,
            'executed_at' => $refund->executed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financialSnapshot(RefundRequest $refund): array
    {
        return [
            'amount' => $refund->amount,
            'refund_amount' => $refund->refund_amount,
            'total_paid_amount' => $refund->total_paid_amount,
            'already_refunded_amount' => $refund->already_refunded_amount,
            'maximum_refundable' => $refund->maximum_refundable,
            'cancellation_charges' => $refund->cancellation_charges,
            'gst_on_cancellation' => $refund->gst_on_cancellation,
            'other_deduction' => $refund->other_deduction,
            'total_deduction' => $refund->total_deduction,
            'deduction_profile_key' => $refund->deduction_profile_key?->value,
            'partial_difference_reason' => $refund->partial_difference_reason?->value,
            'partial_difference_notes' => $refund->partial_difference_notes,
            'deduction_snapshot' => $refund->deduction_snapshot,
        ];
    }
}
