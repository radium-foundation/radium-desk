<?php

namespace App\Services\Refunds;

use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundCaseCloseService;
use App\Services\RefundNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post-financial-commit refund workflow: close linked case, holds, and customer notifications.
 *
 * Safe to run more than once when the refund is already completed or closed.
 */
class RefundCompletionFollowUpService
{
    public function __construct(
        private readonly RefundCaseCloseService $caseCloseService,
        private readonly RefundNotificationService $notificationService,
    ) {}

    public function run(RefundRequest $refund, User $actor, ?Request $request = null): void
    {
        $refund->refresh();

        $this->caseCloseService->closeLinkedCase($refund, $actor, $request);

        $closed = $refund->fresh(['requester']) ?? $refund;

        try {
            $this->notificationService->notifyCustomer(
                refund: $closed,
                actor: $actor,
                channels: $closed->communication_channels,
                request: $request,
            );
        } catch (Throwable $exception) {
            Log::warning('[Refunds] Customer notification failed after refund completion; workflow continued.', [
                'refund_id' => $closed->id,
                'reference_no' => $closed->reference_no,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            $this->notificationService->notifyRequesterOfDecision($closed, 'completed');
        } catch (Throwable $exception) {
            Log::warning('[Refunds] Requester notification failed after refund completion; workflow continued.', [
                'refund_id' => $closed->id,
                'reference_no' => $closed->reference_no,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
