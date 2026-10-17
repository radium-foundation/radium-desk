<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitOnlineReturnRefundRequestRequest;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\ReturnExchangeCaseOnlineRefundSubmitService;
use Illuminate\Http\RedirectResponse;

class ReturnExchangeCaseOnlineRefundController extends Controller
{
    public function __construct(
        private readonly ReturnExchangeCaseOnlineRefundSubmitService $submissions,
    ) {}

    public function store(SubmitOnlineReturnRefundRequestRequest $request, ReturnExchangeCase $returnCase): RedirectResponse
    {
        $refund = $this->submissions->submit(
            $request->user(),
            $returnCase,
            $request->validated(),
            $request,
        );

        return redirect()
            ->route('return-cases.show', $returnCase)
            ->with('status', 'Refund request '.$refund->reference_no.' created and linked to this case. Central Finance can review via Refunds.');
    }
}
