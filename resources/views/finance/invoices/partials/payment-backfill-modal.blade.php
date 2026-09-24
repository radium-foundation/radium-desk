<div class="modal fade" id="paymentBackfillModal" tabindex="-1" aria-labelledby="paymentBackfillModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('finance.invoices.payment-backfill', $invoice) }}" class="modal-content" id="paymentBackfillForm">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title h5" id="paymentBackfillModalLabel">Backfill historical payment</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    One-time Admin reconciliation for historical POS invoice {{ $invoice->invoice_number }}.
                    POS checkout tender is not treated as payment proof.
                </p>
                <div class="row small mb-3">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Invoice:</strong> {{ $invoice->invoice_number }}</p>
                        <p class="mb-1"><strong>Customer:</strong> {{ $invoice->buyer_name ?: '—' }}</p>
                        @if($paymentSummary->inventorySaleReference)
                            <p class="mb-1"><strong>POS / sale:</strong> {{ $paymentSummary->inventorySaleReference }}</p>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Invoice date:</strong> {{ $invoice->issued_at?->timezone(config('app.timezone'))->format('d M Y') ?: '—' }}</p>
                        <p class="mb-1"><strong>Invoice amount:</strong> ₹{{ number_format($paymentSummary->invoiceValue, 2) }}</p>
                        <p class="mb-0"><strong>Current payment state:</strong> {{ $paymentSummary->status->label() }}</p>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="backfill-outcome" class="form-label">Payment status</label>
                    <select id="backfill-outcome" name="outcome" class="form-select @error('outcome') is-invalid @enderror" required>
                        <option value="">Select outcome</option>
                        <option value="unpaid" @selected(old('outcome') === 'unpaid')>Unpaid</option>
                        <option value="partially_paid" @selected(old('outcome') === 'partially_paid')>Partially Paid</option>
                        <option value="paid" @selected(old('outcome') === 'paid')>Paid</option>
                    </select>
                    @error('outcome')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div id="backfill-payment-fields" class="d-none">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="backfill-amount" class="form-label">Amount received</label>
                            <input type="number" step="0.01" min="0.01" id="backfill-amount" name="amount" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}">
                            @error('amount')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="backfill-payment-date" class="form-label">Payment date</label>
                            <input type="date" id="backfill-payment-date" name="payment_date" class="form-control @error('payment_date') is-invalid @enderror" value="{{ old('payment_date') }}">
                            @error('payment_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="backfill-payment-method" class="form-label">Payment method</label>
                            <select id="backfill-payment-method" name="payment_method" class="form-select @error('payment_method') is-invalid @enderror">
                                <option value="">Select method</option>
                                @foreach($backfillPaymentMethods as $method)
                                    <option value="{{ $method->value }}" @selected(old('payment_method') === $method->value)>{{ $method->label() }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6" id="backfill-bank-field">
                            <label for="backfill-bank-name" class="form-label">Bank</label>
                            <input type="text" id="backfill-bank-name" name="bank_name" class="form-control @error('bank_name') is-invalid @enderror" value="{{ old('bank_name') }}">
                            @error('bank_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6" id="backfill-branch-field">
                            <label for="backfill-bank-branch" class="form-label">Branch</label>
                            <input type="text" id="backfill-bank-branch" name="bank_branch" class="form-control @error('bank_branch') is-invalid @enderror" value="{{ old('bank_branch') }}">
                            @error('bank_branch')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6" id="backfill-reference-field">
                            <label for="backfill-reference" class="form-label">Reference / UTR</label>
                            <input type="text" id="backfill-reference" name="reference" class="form-control @error('reference') is-invalid @enderror" value="{{ old('reference') }}">
                            @error('reference')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label for="backfill-remark" class="form-label">Notes / verification remark</label>
                    <textarea id="backfill-remark" name="verification_remark" class="form-control @error('verification_remark') is-invalid @enderror" rows="2">{{ old('verification_remark') }}</textarea>
                    @error('verification_remark')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-check">
                    <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" value="1" id="backfill-confirm" name="confirm" required>
                    <label class="form-check-label" for="backfill-confirm">
                        I have verified this historical payment information.
                    </label>
                    @error('confirm')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-warning">Submit reconciliation</button>
            </div>
        </form>
    </div>
</div>
<script>
    (function () {
        const outcome = document.getElementById('backfill-outcome');
        const paymentFields = document.getElementById('backfill-payment-fields');
        const amount = document.getElementById('backfill-amount');
        const method = document.getElementById('backfill-payment-method');
        const bankField = document.getElementById('backfill-bank-field');
        const branchField = document.getElementById('backfill-branch-field');
        const referenceField = document.getElementById('backfill-reference-field');
        const invoiceValue = {{ json_encode((float) $paymentSummary->invoiceValue) }};

        function syncOutcome() {
            const value = outcome.value;
            const needsPayment = value === 'partially_paid' || value === 'paid';
            paymentFields.classList.toggle('d-none', !needsPayment);
            amount.required = needsPayment;
            method.required = needsPayment;
            document.getElementById('backfill-payment-date').required = needsPayment;
            if (value === 'paid') {
                amount.value = invoiceValue.toFixed(2);
                amount.readOnly = true;
            } else {
                amount.readOnly = false;
            }
        }

        function syncMethod() {
            const value = method.value;
            const isCash = value === 'cash';
            const isOtherBank = value === 'other_bank';
            bankField.classList.toggle('d-none', isCash || !isOtherBank);
            branchField.classList.toggle('d-none', isCash);
            referenceField.classList.toggle('d-none', isCash);
        }

        outcome.addEventListener('change', syncOutcome);
        method.addEventListener('change', syncMethod);
        syncOutcome();
        syncMethod();
    })();
</script>
