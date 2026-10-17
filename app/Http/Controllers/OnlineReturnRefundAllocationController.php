<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExecuteOnlineReturnRefundAllocationLineRequest;
use App\Http\Requests\ReviewOnlineReturnRefundAllocationRequest;
use App\Http\Requests\SubmitOnlineReturnRefundAllocationRequest;
use App\Models\OnlineReturnRefundAllocation;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\OnlineReturnRefundAllocationExecutionService;
use App\Services\ReturnExchange\OnlineReturnRefundAllocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OnlineReturnRefundAllocationController extends Controller
{
    public function __construct(
        private readonly OnlineReturnRefundAllocationService $allocations,
        private readonly OnlineReturnRefundAllocationExecutionService $execution,
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

    public function executeLine(
        ExecuteOnlineReturnRefundAllocationLineRequest $request,
        OnlineReturnRefundAllocationLine $line,
    ): RedirectResponse {
        $line = $line->loadMissing('allocation.returnCase');
        $allocation = $line->allocation;
        if ($allocation === null) {
            abort(404);
        }

        try {
            $result = $this->execution->executeLine(
                $request->user(),
                $allocation,
                $line,
                $request->validated(),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('return-cases.show', $allocation->returnCase)
                ->withErrors($exception->errors());
        }

        $case = $allocation->returnCase;

        return redirect()
            ->route('return-cases.show', $case)
            ->with('status', 'Allocation line '.$line->line_no.' execution: '.$result->status->label().'.');
    }
}
