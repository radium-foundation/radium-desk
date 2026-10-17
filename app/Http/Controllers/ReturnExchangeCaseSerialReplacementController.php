<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReturnExchangeCaseSerialReplacementRequest;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\ReturnExchangeCaseSerialReplacementService;
use Illuminate\Http\RedirectResponse;

class ReturnExchangeCaseSerialReplacementController extends Controller
{
    public function __construct(
        private readonly ReturnExchangeCaseSerialReplacementService $replacements,
    ) {}

    public function store(
        StoreReturnExchangeCaseSerialReplacementRequest $request,
        ReturnExchangeCase $returnCase,
    ): RedirectResponse {
        $this->replacements->perform($request->user(), $returnCase, $request->validated());

        return redirect()
            ->route('return-cases.show', $returnCase)
            ->with('status', 'Serial replacement recorded for '.$returnCase->case_no.'.');
    }
}
