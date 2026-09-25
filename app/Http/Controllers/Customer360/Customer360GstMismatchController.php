<?php

namespace App\Http\Controllers\Customer360;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\ServiceStatutoryGstMismatchException;
use App\Services\StatutoryInvoice\ServiceStatutoryGstMismatchExceptionService;
use App\Services\StatutoryInvoice\ServiceStatutoryGstMismatchIssuanceService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Customer360GstMismatchController extends Controller
{
    public function __construct(
        private readonly ServiceStatutoryGstMismatchExceptionService $exceptions,
        private readonly ServiceStatutoryGstMismatchIssuanceService $issuance,
    ) {}

    public function storeCorrection(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsInvoiceIssue($request->user()), 403);

        $exception = ServiceStatutoryGstMismatchException::query()
            ->where(function ($query) use ($incident): void {
                $query->where('incident_id', $incident->id)
                    ->orWhere('support_order_id', $incident->order_id);
            })
            ->orderByDesc('id')
            ->firstOrFail();

        $validated = $request->validate([
            'buyer_gstin' => ['required', 'string', 'size:15'],
            'billing_state' => ['required', 'string', 'max:64'],
            'place_of_supply_state' => ['nullable', 'string', 'max:64'],
            'billing_pincode' => ['nullable', 'string', 'max:16'],
            'billing_line1' => ['nullable', 'string', 'max:255'],
            'billing_city' => ['nullable', 'string', 'max:128'],
            'verification_notes' => ['required', 'string', 'max:2000'],
        ]);

        $structured = $exception->original_billing_address_structured ?? [];
        if (! is_array($structured)) {
            $structured = [];
        }
        if (filled($validated['billing_line1'] ?? null)) {
            $structured['line1'] = $validated['billing_line1'];
        }
        if (filled($validated['billing_city'] ?? null)) {
            $structured['city'] = $validated['billing_city'];
        }
        if (filled($validated['billing_pincode'] ?? null)) {
            $structured['pincode'] = $validated['billing_pincode'];
        }
        $structured['state'] = $validated['billing_state'];

        $exception = $this->exceptions->recordVerifiedCorrection(
            $exception,
            [
                'buyer_gstin' => $validated['buyer_gstin'],
                'billing_state' => $validated['billing_state'],
                'place_of_supply_state' => $validated['place_of_supply_state'] ?? $validated['billing_state'],
                'billing_pincode' => $validated['billing_pincode'] ?? null,
                'billing_address_structured' => $structured,
            ],
            $request->user(),
        );

        try {
            $this->issuance->issueB2bIfReady($exception, $request->user()->id);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('status', 'Verified GST correction recorded. B2B statutory invoice issuance has been queued.');
    }
}
