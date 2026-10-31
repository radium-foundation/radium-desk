<?php

namespace App\Http\Controllers;

use App\Models\RefundRequest;
use App\Models\WalletRefundOpmConversion;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmConversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalletRefundOpmConversionController extends Controller
{
    public function __construct(
        private readonly WalletRefundOpmConversionService $conversions,
    ) {}

    public function store(Request $request, RefundRequest $refund): RedirectResponse
    {
        $this->authorize('create', WalletRefundOpmConversion::class);

        $conversion = $this->conversions->submit($request->user(), $refund);

        return redirect()
            ->route('refunds.show', $refund)
            ->with('status', 'Wallet-to-OPM conversion '.$conversion->public_id.' submitted for approval.');
    }

    public function approve(Request $request, WalletRefundOpmConversion $conversion): RedirectResponse
    {
        abort_unless($request->user()?->can('refunds.wallet_opm_conversion.approve'), 403);

        $this->conversions->approve($request->user(), $conversion);

        return back()->with('status', 'Conversion approved and funds reserved.');
    }

    public function reject(Request $request, WalletRefundOpmConversion $conversion): RedirectResponse
    {
        abort_unless($request->user()?->can('refunds.wallet_opm_conversion.approve'), 403);

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        $this->conversions->reject($request->user(), $conversion, $reason);

        return back()->with('status', 'Conversion rejected.');
    }

    public function recordPayout(Request $request, WalletRefundOpmConversion $conversion): RedirectResponse
    {
        abort_unless($request->user()?->can('refunds.wallet_opm_conversion.payout'), 403);

        $validated = $request->validate([
            'cashfree_order_id' => ['required', 'string', 'max:64'],
            'cashfree_refund_id' => ['required', 'string', 'max:128'],
            'cashfree_refund_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $this->conversions->recordPayout(
            $request->user(),
            $conversion,
            $validated['cashfree_order_id'],
            $validated['cashfree_refund_id'],
            $validated['cashfree_refund_amount'],
        );

        return back()->with('status', 'Payout recorded and verification attempted.');
    }

    public function verify(Request $request, WalletRefundOpmConversion $conversion): RedirectResponse
    {
        abort_unless($request->user()?->can('refunds.wallet_opm_conversion.payout'), 403);

        $this->conversions->verifyAndFinalize($request->user(), $conversion);

        return back()->with('status', 'Verification and finalization attempted.');
    }

    public function release(Request $request, WalletRefundOpmConversion $conversion): RedirectResponse
    {
        abort_unless($request->user()?->can('refunds.wallet_opm_conversion.release'), 403);

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A release reason is required.']);
        }

        $this->conversions->authorizeRelease($request->user(), $conversion, $reason);

        return back()->with('status', 'Reservation released and conversion cancelled.');
    }
}
