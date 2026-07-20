<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefundCaseCloseService
{
    public function __construct(
        private readonly ServiceCaseStatusService $statusService,
        private readonly RemarkService $remarkService,
        private readonly AuditLogService $auditLogService,
        private readonly BusinessHoldService $businessHoldService,
    ) {}

    public function closeLinkedCase(RefundRequest $refund, User $actor, ?Request $request = null): void
    {
        $refund->loadMissing('incident');

        $incident = $refund->incident;

        if (! $incident instanceof Incident) {
            $this->markRefundClosed($refund, $actor, $request);

            return;
        }

        if ($incident->status === IncidentStatus::Closed) {
            $this->markRefundClosed($refund, $actor, $request);

            return;
        }

        try {
            DB::transaction(function () use ($incident, $refund, $actor, $request): void {
                $this->businessHoldService->clearActiveHold(
                    incident: $incident,
                    actor: $actor,
                    source: 'refund_completed',
                    type: \App\Enums\BusinessHoldType::Refund,
                );

                $this->remarkService->createForRemarkable(
                    remarkable: $incident,
                    actor: $actor,
                    body: "Service case closed after refund {$refund->reference_no} was completed.",
                    request: $request,
                );

                $this->statusService->updateStatus($incident, IncidentStatus::Closed, $actor);
                $this->markRefundClosed($refund, $actor, $request);
            });
        } catch (Throwable $exception) {
            // Payout already completed — keep refund completed and record close failure.
            $this->auditLogService->log(
                userId: $actor->id,
                event: 'refund.closed',
                auditable: $refund,
                newValues: [
                    'success' => false,
                    'incident_id' => $incident->id,
                    'error' => $exception->getMessage(),
                    'reference_no' => $refund->reference_no,
                ],
                request: $request,
            );
        }
    }

    private function markRefundClosed(RefundRequest $refund, User $actor, ?Request $request): void
    {
        if ($refund->status->value === 'closed') {
            return;
        }

        $refund->update([
            'status' => \App\Enums\RefundStatus::Closed,
            'closed_at' => now(),
        ]);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'refund.closed',
            auditable: $refund->fresh(),
            newValues: [
                'success' => true,
                'incident_id' => $refund->incident_id,
                'closed_at' => now()->toIso8601String(),
                'reference_no' => $refund->reference_no,
            ],
            request: $request,
        );
    }
}
