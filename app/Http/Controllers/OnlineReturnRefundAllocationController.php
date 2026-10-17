<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewOnlineReturnRefundAllocationRequest;
use App\Http\Requests\SubmitOnlineReturnRefundAllocationRequest;
use App\Models\OnlineReturnRefundAllocation;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\OnlineReturnRefundAllocationService;
use Illuminate\Http\RedirectResponse;

class OnlineReturnRefundAllocationController extends Controller
{
    public function __construct(
        private readonly OnlineReturnRefundAllocationService $allocations,
    ) {}

    public function store(
        SubmitOnlineReturnRefundAllocationRequest $request,
        ReturnExchangeCase $returnCase,
    ): RedirectResponse {
        $allocation = $this->allocations->submit(
            $request->user(),
            $returnCase,
            $request->validated(),
        );

        return redirect()
            ->route('return-cases.show', $returnCase)
            ->with('status', 'Finance allocation '.$allocation->reference_no.' submitted for review (no payout in this step).');
    }

    public function review(
        ReviewOnlineReturnRefundAllocationRequest $request,
        OnlineReturnRefundAllocation $onlineReturnRefundAllocation,
    ): RedirectResponse {
        $validated = $request->validated();
        $actor = $request->user();
        $reason = $validated['review_reason'] ?? null;

        if ((string) $validated['decision'] === 'approve') {
            $updated = $this->allocations->approve($actor, $onlineReturnRefundAllocation, $reason);
            $message = 'Finance allocation '.$updated->reference_no.' approved (records destination amounts only; no payout executed).';
        } else {
            $updated = $this->allocations->reject($actor, $onlineReturnRefundAllocation, (string) $reason);
            $message = 'Finance allocation '.$updated->reference_no.' rejected.';
        }

        $case = $updated->returnCase ?? $onlineReturnRefundAllocation->returnCase;

        return redirect()
            ->route('return-cases.show', $case)
            ->with('status', $message);
    }
}
