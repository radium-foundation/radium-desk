<?php

namespace App\Jobs\Refunds;

use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\RefundCompletionFollowUpService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs refund case-close and notifications after the HTTP response is sent.
 */
class RefundCompletionFollowUpJob
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly int $refundId,
        public readonly int $actorUserId,
    ) {}

    public function handle(RefundCompletionFollowUpService $followUp): void
    {
        $refund = RefundRequest::query()->find($this->refundId);
        $actor = User::query()->find($this->actorUserId);

        if ($refund === null || $actor === null) {
            Log::warning('[Refunds] Completion follow-up skipped; refund or actor missing.', [
                'refund_id' => $this->refundId,
                'actor_user_id' => $this->actorUserId,
            ]);

            return;
        }

        try {
            $followUp->run($refund, $actor);
        } catch (Throwable $exception) {
            Log::error('[Refunds] Completion follow-up failed after financial commit.', [
                'refund_id' => $refund->id,
                'reference_no' => $refund->reference_no,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
