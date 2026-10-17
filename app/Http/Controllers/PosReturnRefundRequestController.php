<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewPosReturnRefundRequestRequest;
use App\Http\Requests\SubmitPosReturnRefundRequestRequest;
use App\Models\PosReturnRefundRequest;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\PosReturnRefundRequestService;
use Illuminate\Http\RedirectResponse;

class PosReturnRefundRequestController extends Controller
{
    public function __construct(
        private readonly PosReturnRefundRequestService $service,
    ) {}

    public function store(SubmitPosReturnRefundRequestRequest $request, ReturnExchangeCase $returnCase): RedirectResponse
    {
        $refundRequest = $this->service->submit(
            $request->user(),
            $returnCase,
            $request->validated(),
        );

        return redirect()
            ->route('return-cases.show', $returnCase)
            ->with('status', 'POS refund request '.$refundRequest->reference_no.' submitted for Finance review (no payout in this step).');
    }

    public function review(ReviewPosReturnRefundRequestRequest $request, PosReturnRefundRequest $posReturnRefundRequest): RedirectResponse
    {
        $validated = $request->validated();
        $actor = $request->user();
        $reason = $validated['review_reason'] ?? null;

        $decision = (string) $validated['decision'];

        if ($decision === 'approve') {
            $updated = $this->service->approve($actor, $posReturnRefundRequest, $reason);
            $message = 'POS refund request '.$updated->reference_no.' approved for Finance processing (no payout in this step).';
        } else {
            $updated = $this->service->reject($actor, $posReturnRefundRequest, (string) $reason);
            $message = 'POS refund request '.$updated->reference_no.' rejected.';
        }

        $case = $updated->returnCase ?? $posReturnRefundRequest->returnCase;

        return redirect()
            ->route('return-cases.show', $case)
            ->with('status', $message);
    }
}
