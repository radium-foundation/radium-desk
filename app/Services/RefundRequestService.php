<?php

namespace App\Services;

use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundRequestService
{
    public function __construct(
        private readonly RefundReferenceService $referenceService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, Request $request): RefundRequest
    {
        $refund = DB::transaction(function () use ($user, $data): RefundRequest {
            return RefundRequest::query()->create([
                'order_id' => $data['order_id'],
                'incident_id' => $data['incident_id'] ?? null,
                'reference_no' => $this->referenceService->generate(),
                'amount' => $data['amount'],
                'reason' => $data['reason'],
                'status' => RefundStatus::Pending,
                'requested_by' => $user->id,
            ]);
        });

        $this->auditLogService->log(
            userId: $user->id,
            event: 'created',
            auditable: $refund,
            newValues: [
                'reference_no' => $refund->reference_no,
                'order_id' => $refund->order_id,
                'incident_id' => $refund->incident_id,
                'amount' => $refund->amount,
                'status' => $refund->status->value,
            ],
            request: $request,
        );

        return $refund;
    }

    public function approve(
        RefundRequest $refund,
        User $user,
        ?string $reviewNotes,
        string $refundTransactionId,
        Request $request,
    ): RefundRequest {
        return DB::transaction(function () use ($refund, $user, $reviewNotes, $refundTransactionId, $request): RefundRequest {
            $this->ensurePending($refund);

            $oldValues = $this->reviewSnapshot($refund);

            $refund->update([
                'status' => RefundStatus::Approved,
                'review_notes' => $reviewNotes,
                'refund_transaction_id' => $refundTransactionId,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'approved',
                auditable: $refund,
                oldValues: $oldValues,
                newValues: $this->reviewSnapshot($refund->fresh()),
                request: $request,
            );

            return $refund->fresh();
        });
    }

    public function reject(
        RefundRequest $refund,
        User $user,
        string $reviewNotes,
        Request $request,
    ): RefundRequest {
        return DB::transaction(function () use ($refund, $user, $reviewNotes, $request): RefundRequest {
            $this->ensurePending($refund);

            $oldValues = $this->reviewSnapshot($refund);

            $refund->update([
                'status' => RefundStatus::Rejected,
                'review_notes' => $reviewNotes,
                'refund_transaction_id' => null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'rejected',
                auditable: $refund,
                oldValues: $oldValues,
                newValues: $this->reviewSnapshot($refund->fresh()),
                request: $request,
            );

            return $refund->fresh();
        });
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

    private function ensurePending(RefundRequest $refund): void
    {
        if ($refund->status !== RefundStatus::Pending) {
            throw ValidationException::withMessages([
                'refund' => 'Only pending refund requests can be reviewed.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewSnapshot(RefundRequest $refund): array
    {
        return [
            'status' => $refund->status->value,
            'review_notes' => $refund->review_notes,
            'refund_transaction_id' => $refund->refund_transaction_id,
            'reviewed_by' => $refund->reviewed_by,
            'reviewed_at' => $refund->reviewed_at?->toIso8601String(),
        ];
    }
}
