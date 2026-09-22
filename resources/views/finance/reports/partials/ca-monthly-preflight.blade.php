@php
    $attentionCount = $preflight->nonReconcilingLineCount
        + $preflight->cancelledAmbiguousInvoiceValueOnlyCount
        + $preflight->unclassifiedOrderCount;

    $warningCount = $preflight->missingOrderDateCount
        + $preflight->missingHsnSacLineCount
        + $preflight->missingIrnInvoiceCount
        + $preflight->missingAcknowledgementInvoiceCount
        + $preflight->missingStateInvoiceCount;

    $informationalMessages = collect($preflight->warnings())->filter(function (string $message): bool {
        return str_contains($message, 'No statutory invoice lines')
            || str_contains($message, 'Period filter uses Date of Invoice')
            || str_contains($message, 'cancelled invoice(s) are included')
            || str_contains($message, 'payment reference evidence')
            || str_contains($message, 'payment method evidence')
            || str_contains($message, 'payment allocation evidence')
            || str_contains($message, 'credit note row(s)')
            || str_contains($message, 'line discount');
    });

    $attentionMessages = collect($preflight->warnings())->filter(function (string $message): bool {
        return str_contains($message, 'no authoritative payment evidence')
            || str_contains($message, 'could not be authoritatively classified')
            || str_contains($message, 'do not reconcile');
    });

    $validationMessages = collect($preflight->warnings())->filter(function (string $message): bool {
        return str_contains($message, 'missing Date_of_order')
            || str_contains($message, 'missing SAC/HSN')
            || str_contains($message, 'no buyer GSTIN')
            || str_contains($message, 'no IRN on file')
            || str_contains($message, 'no acknowledgement number')
            || str_contains($message, 'no buyer STATE')
            || str_contains($message, 'no eWay Bill')
            || str_contains($message, 'no Shipping amount');
    });
@endphp

<section class="card border-0 shadow-sm mb-3" aria-labelledby="ca-monthly-preflight-heading">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h2 class="h6 mb-1" id="ca-monthly-preflight-heading">Preflight summary</h2>
                <p class="small text-muted mb-0">Validation and totals for the selected reporting period.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if ($attentionCount > 0)
                    <span class="badge text-bg-warning">{{ $attentionCount }} need attention</span>
                @endif
                @if ($warningCount > 0)
                    <span class="badge text-bg-secondary">{{ $warningCount }} validation note(s)</span>
                @endif
                @if ($attentionCount === 0 && $warningCount === 0 && $preflight->lineCount > 0)
                    <span class="badge text-bg-success">Ready to export</span>
                @endif
            </div>
        </div>

        <div class="row g-2 g-md-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 p-md-3 h-100 bg-light">
                    <div class="small text-muted">Statutory invoices</div>
                    <div class="fs-5 fw-semibold">{{ number_format($preflight->invoiceCount) }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 p-md-3 h-100 bg-light">
                    <div class="small text-muted">Invoice lines</div>
                    <div class="fs-5 fw-semibold">{{ number_format($preflight->lineCount) }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 p-md-3 h-100 bg-light">
                    <div class="small text-muted">Taxable amount</div>
                    <div class="fs-6 fw-semibold">₹{{ $preflight->taxableAmountTotal }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 p-md-3 h-100 bg-light">
                    <div class="small text-muted">Total amount</div>
                    <div class="fs-6 fw-semibold">₹{{ $preflight->totalAmountTotal }}</div>
                </div>
            </div>
        </div>

        <div class="row g-2 small mb-3">
            <div class="col-md-4">
                <span class="text-muted">Order mix:</span>
                {{ number_format($preflight->hardwareOrderCount) }} hardware ·
                {{ number_format($preflight->serviceOrderCount) }} service ·
                {{ number_format($preflight->bundledOrderCount) }} bundled
            </div>
            <div class="col-md-4">
                <span class="text-muted">GST totals:</span>
                IGST ₹{{ $preflight->igstTotal }} · CGST ₹{{ $preflight->cgstTotal }} · SGST ₹{{ $preflight->sgstTotal }}
            </div>
            <div class="col-md-4">
                <span class="text-muted">Shipping / short-excess:</span>
                ₹{{ $preflight->shippingAmountTotal }} · ₹{{ $preflight->shortExcessTotal }}
            </div>
        </div>

        @if ($informationalMessages->isNotEmpty())
            <div class="mb-2">
                <div class="small fw-semibold text-muted mb-1">Informational</div>
                @foreach ($informationalMessages as $message)
                    <div class="alert alert-info py-2 small mb-2">{{ $message }}</div>
                @endforeach
            </div>
        @endif

        @if ($attentionMessages->isNotEmpty())
            <div class="mb-2">
                <div class="small fw-semibold text-muted mb-1">Attention</div>
                @foreach ($attentionMessages as $message)
                    <div class="alert alert-warning py-2 small mb-2">{{ $message }}</div>
                @endforeach
            </div>
        @endif

        @if ($validationMessages->isNotEmpty())
            <details class="mb-2">
                <summary class="small fw-semibold text-muted">Validation notes ({{ $validationMessages->count() }})</summary>
                <div class="mt-2">
                    @foreach ($validationMessages as $message)
                        <div class="alert alert-secondary py-2 small mb-2">{{ $message }}</div>
                    @endforeach
                </div>
            </details>
        @endif

        <details>
            <summary class="small fw-semibold">Full preflight metrics</summary>
            <ul class="small mb-0 mt-2">
                <li>Statutory invoices: {{ $preflight->invoiceCount }}</li>
                <li>Invoice lines: {{ $preflight->lineCount }}</li>
                <li>Hardware orders: {{ $preflight->hardwareOrderCount }}</li>
                <li>Service orders: {{ $preflight->serviceOrderCount }}</li>
                <li>Bundled orders: {{ $preflight->bundledOrderCount }}</li>
                <li>Unclassified Ordertype: {{ $preflight->unclassifiedOrderCount }} invoice(s)</li>
                <li>Cancelled included: {{ $preflight->cancelledIncludedCount }}</li>
                <li>Cancelled with payment reference evidence: {{ $preflight->cancelledIncludedViaPaymentReferenceCount }}</li>
                <li>Cancelled with payment method evidence: {{ $preflight->cancelledIncludedViaPaymentMethodCount }}</li>
                <li>Cancelled with Service POS allocation evidence: {{ $preflight->cancelledIncludedViaPaymentAllocationCount }}</li>
                <li>Cancelled with invoice value only (no payment evidence): {{ $preflight->cancelledAmbiguousInvoiceValueOnlyCount }}</li>
                <li>Credit notes: {{ $preflight->creditNoteCount }}</li>
                <li>Lines missing Date_of_order: {{ $preflight->missingOrderDateCount }}</li>
                <li>Lines missing SAC/HSN: {{ $preflight->missingHsnSacLineCount }}</li>
                <li>Invoices missing buyer GSTIN: {{ $preflight->missingBuyerGstinInvoiceCount }}</li>
                <li>Invoices missing IRN: {{ $preflight->missingIrnInvoiceCount }}</li>
                <li>Invoices missing acknowledgement number: {{ $preflight->missingAcknowledgementInvoiceCount }}</li>
                <li>Invoices missing STATE: {{ $preflight->missingStateInvoiceCount }}</li>
                <li>Lines with line discount applied: {{ $preflight->discountLineCount }}</li>
                <li>Lines not reconciling: {{ $preflight->nonReconcilingLineCount }}</li>
                <li>Taxable Amount total: ₹{{ $preflight->taxableAmountTotal }}</li>
                <li>Shipping total: ₹{{ $preflight->shippingAmountTotal }}</li>
                <li>IGST total: ₹{{ $preflight->igstTotal }}</li>
                <li>CGST total: ₹{{ $preflight->cgstTotal }}</li>
                <li>SGST total: ₹{{ $preflight->sgstTotal }}</li>
                <li>Short/Excess total: ₹{{ $preflight->shortExcessTotal }}</li>
                <li>Total Amount total: ₹{{ $preflight->totalAmountTotal }}</li>
            </ul>
        </details>
    </div>
</section>
