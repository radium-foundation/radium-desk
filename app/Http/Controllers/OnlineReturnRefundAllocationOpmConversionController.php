<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveOnlineReturnRefundAllocationOpmConversionRequest;
use App\Http\Requests\VerifyOnlineReturnRefundAllocationOpmConversionRequest;
use App\Http\Requests\RecordOnlineReturnRefundAllocationOpmPayoutRequest;
use App\Http\Requests\RejectOnlineReturnRefundAllocationOpmConversionRequest;
use App\Http\Requests\ReleaseOnlineReturnRefundAllocationOpmConversionRequest;
use App\Http\Requests\SubmitOnlineReturnRefundAllocationOpmConversionRequest;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Services\ReturnExchange\ReturnExchangeAllocationOpmConversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OnlineReturnRefundAllocationOpmConversionController extends Controller
{
    public function __construct(
        private readonly ReturnExchangeAllocationOpmConversionService $conversions,
    ) {}

    public function store(
        SubmitOnlineReturnRefundAllocationOpmConversionRequest $request,
        OnlineReturnRefundAllocationLine $line,
    ): RedirectResponse {
        $line->loadMissing(['allocation.returnCase', 'successfulExecution']);
        $execution = $line->successfulExecution;
        if ($execution === null) {
            abort(404);
        }

        try {
            $conversion = $this->conversions->submit(
                $request->user(),
                $line,
                $execution,
                $request->validated(),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('return-cases.show', $line->allocation?->returnCase)
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('return-cases.show', $line->allocation?->returnCase)
            ->with('status', 'Wallet-to-OPM conversion '.$conversion->public_id.' submitted for Finance approval (no payout yet).');
    }

    public function approve(
        ApproveOnlineReturnRefundAllocationOpmConversionRequest $request,
        OnlineReturnRefundAllocationOpmConversion $conversion,
    ): RedirectResponse {
        try {
            $updated = $this->conversions->approve($request->user(), $conversion);
        } catch (ValidationException $exception) {
            return $this->redirectWithErrors($conversion, $exception);
        }

        return redirect()
            ->route('return-cases.show', $updated->returnCase)
            ->with('status', 'Conversion reserved at site (manual Cashfree payout still required). Status: '.$updated->status->value);
    }

    public function reject(
        RejectOnlineReturnRefundAllocationOpmConversionRequest $request,
        OnlineReturnRefundAllocationOpmConversion $conversion,
    ): RedirectResponse {
        $updated = $this->conversions->reject(
            $request->user(),
            $conversion,
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('return-cases.show', $updated->returnCase)
            ->with('status', 'Wallet-to-OPM conversion rejected.');
    }

    public function recordPayout(
        RecordOnlineReturnRefundAllocationOpmPayoutRequest $request,
        OnlineReturnRefundAllocationOpmConversion $conversion,
    ): RedirectResponse {
        try {
            $updated = $this->conversions->recordPayout(
                $request->user(),
                $conversion,
                (string) $request->validated('cashfree_order_id'),
                (string) $request->validated('cashfree_refund_id'),
                (string) $request->validated('cashfree_refund_amount'),
            );
        } catch (ValidationException $exception) {
            return $this->redirectWithErrors($conversion, $exception);
        }

        return redirect()
            ->route('return-cases.show', $updated->returnCase)
            ->with('status', 'Payout evidence recorded. Conversion status: '.$updated->status->value);
    }

    public function verifyAndFinalize(
        VerifyOnlineReturnRefundAllocationOpmConversionRequest $request,
        OnlineReturnRefundAllocationOpmConversion $conversion,
    ): RedirectResponse {
        try {
            $updated = $this->conversions->verifyAndFinalize($request->user(), $conversion);
        } catch (ValidationException $exception) {
            return $this->redirectWithErrors($conversion, $exception);
        }

        return redirect()
            ->route('return-cases.show', $updated->returnCase)
            ->with('status', 'Verification/finalization complete. Status: '.$updated->status->value);
    }

    public function release(
        ReleaseOnlineReturnRefundAllocationOpmConversionRequest $request,
        OnlineReturnRefundAllocationOpmConversion $conversion,
    ): RedirectResponse {
        $updated = $this->conversions->authorizeRelease(
            $request->user(),
            $conversion,
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('return-cases.show', $updated->returnCase)
            ->with('status', 'Reservation released; conversion cancelled.');
    }

    private function redirectWithErrors(
        OnlineReturnRefundAllocationOpmConversion $conversion,
        ValidationException $exception,
    ): RedirectResponse {
        return redirect()
            ->route('return-cases.show', $conversion->returnCase)
            ->withErrors($exception->errors());
    }
}
