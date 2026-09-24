<?php

namespace App\Http\Controllers\Finance;

use App\Enums\PosHistoricalPaymentMethod;
use App\Enums\StatutoryInvoicePaymentBackfillOutcome;
use App\Http\Controllers\Controller;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentBackfillService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StatutoryInvoicePaymentBackfillController extends Controller
{
    public function __construct(
        private readonly StatutoryInvoicePaymentBackfillService $backfill,
    ) {}

    public function store(Request $request, StatutoryInvoice $invoice): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsPaymentBackfill($request->user()), 403);

        $outcomes = array_map(
            fn (StatutoryInvoicePaymentBackfillOutcome $outcome): string => $outcome->value,
            StatutoryInvoicePaymentBackfillOutcome::cases(),
        );

        $validated = $request->validate([
            'outcome' => ['required', Rule::in($outcomes)],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::in(array_map(
                fn (PosHistoricalPaymentMethod $method): string => $method->value,
                PosHistoricalPaymentMethod::cases(),
            ))],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:128'],
            'verification_remark' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        try {
            $record = $this->backfill->backfill($invoice, $request->user(), $validated);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        $message = match ($record->outcome) {
            StatutoryInvoicePaymentBackfillOutcome::Unpaid => 'Payment reconciliation completed. Invoice remains unpaid.',
            StatutoryInvoicePaymentBackfillOutcome::PartiallyPaid => 'Payment reconciliation completed. Partial payment recorded.',
            StatutoryInvoicePaymentBackfillOutcome::Paid => 'Payment reconciliation completed. Invoice marked paid.',
        };

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('status', $message);
    }
}
