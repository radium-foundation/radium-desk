@extends('layouts.app')

@section('title', 'CA Monthly Report')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Reports</p>
        <h1 class="h3 mb-1">CA Monthly Report</h1>
        <p class="text-muted mb-0">
            Statutory invoice export for CA review. Period filter uses
            <strong>Date of Invoice</strong> (<code>statutory_invoices.issued_at</code>).
            Preview groups multi-product invoices; export retains one row per product line in the 27-column contract.
        </p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'ca_monthly_report'])

    <form method="get" action="{{ route('finance.reports.ca-monthly.index') }}" class="row g-2 mb-3">
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="date_from">From Date</label>
            <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control" required>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="date_to">To Date</label>
            <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-secondary w-100">Apply</button>
        </div>
    </form>

    <div class="alert alert-secondary">
        <div class="fw-semibold mb-2">Preflight</div>
        <ul class="mb-0 small">
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
    </div>

    @foreach ($preflight->warnings() as $warning)
        <div class="alert alert-warning py-2 small mb-2">{{ $warning }}</div>
    @endforeach

    @if ($canExport)
        <p class="mb-3">
            <a href="{{ route('finance.reports.ca-monthly.export.xlsx', request()->query()) }}" class="btn btn-primary">Export XLSX</a>
            <a href="{{ route('finance.reports.ca-monthly.export.csv', request()->query()) }}" class="btn btn-outline-primary">Export CSV</a>
        </p>
    @endif

    <style>
        .ca-monthly-table .ca-monthly-parent-row {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .ca-monthly-table .ca-monthly-child-row td {
            border-top: 0;
            background-color: rgba(0, 0, 0, 0.01);
        }

        .ca-monthly-toggle:focus-visible {
            outline: 2px solid var(--bs-primary);
            outline-offset: 2px;
        }
    </style>

    <div class="table-responsive" id="ca-monthly-report">
        <table class="table table-sm align-middle ca-monthly-table">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="text-nowrap small" style="width:2.5rem;"></th>
                    <th scope="col" class="text-nowrap small">Invoice No.</th>
                    <th scope="col" class="text-nowrap small">Date of Invoice</th>
                    <th scope="col" class="text-nowrap small">Customer</th>
                    <th scope="col" class="text-nowrap small">Ordertype</th>
                    <th scope="col" class="text-nowrap small text-end">Taxable</th>
                    <th scope="col" class="text-nowrap small text-end">Shipping</th>
                    <th scope="col" class="text-nowrap small text-end">Tax</th>
                    <th scope="col" class="text-nowrap small text-end">Total</th>
                    <th scope="col" class="text-nowrap small">Payment</th>
                    <th scope="col" class="text-nowrap small">Status</th>
                    <th scope="col" class="text-nowrap small">Document Type</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoiceGroups as $group)
                    @if ($group->expandable)
                        <tr class="ca-monthly-parent-row fw-semibold">
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-link p-0 ca-monthly-toggle"
                                    data-invoice-id="{{ $group->invoiceId }}"
                                    aria-expanded="false"
                                    aria-controls="ca-monthly-children-{{ $group->invoiceId }}"
                                    title="Expand invoice lines"
                                >+</button>
                            </td>
                            <td>{{ $group->invoiceNumber }}</td>
                            <td>{{ $group->issuedDate }}</td>
                            <td>{{ $group->buyerName !== '' ? $group->buyerName : '—' }}</td>
                            <td>{{ $group->orderType !== '' ? $group->orderType : '—' }}</td>
                            <td class="text-end">{{ $group->taxableAmount }}</td>
                            <td class="text-end">{{ $group->shippingAmount !== '' ? $group->shippingAmount : '—' }}</td>
                            <td class="text-end">{{ $group->taxAmount !== '' ? $group->taxAmount : '—' }}</td>
                            <td class="text-end">{{ $group->totalAmount }}</td>
                            <td>{{ $group->paymentMode !== '' ? $group->paymentMode : '—' }}</td>
                            <td>{{ $group->status }}</td>
                            <td>{{ $group->documentType }}</td>
                        </tr>
                        @foreach ($group->children as $child)
                            <tr
                                id="ca-monthly-children-{{ $group->invoiceId }}"
                                class="ca-monthly-child-row d-none"
                                data-parent-invoice="{{ $group->invoiceId }}"
                            >
                                <td></td>
                                <td colspan="4" class="ps-4 small text-muted">{{ $child->productName }}</td>
                                <td class="text-end small">{{ $child->taxableAmount }}</td>
                                <td class="text-end small">—</td>
                                <td class="text-end small">
                                    @php
                                        $lineTax = array_filter([$child->igst, $child->cgst, $child->sgst]);
                                    @endphp
                                    {{ $lineTax !== [] ? implode(' / ', $lineTax) : '—' }}
                                </td>
                                <td class="text-end small">—</td>
                                <td class="small text-muted">Qty {{ $child->quantity }} · {{ $child->hsnSac !== '' ? $child->hsnSac : '—' }}</td>
                                <td colspan="2"></td>
                            </tr>
                        @endforeach
                    @else
                        <tr>
                            <td></td>
                            <td>{{ $group->invoiceNumber }}</td>
                            <td>{{ $group->issuedDate }}</td>
                            <td>
                                {{ $group->buyerName !== '' ? $group->buyerName : '—' }}
                                @if (($group->children[0] ?? null) !== null)
                                    <div class="small text-muted">{{ $group->children[0]->productName }}</div>
                                @endif
                            </td>
                            <td>{{ $group->orderType !== '' ? $group->orderType : '—' }}</td>
                            <td class="text-end">{{ $group->taxableAmount }}</td>
                            <td class="text-end">{{ $group->shippingAmount !== '' ? $group->shippingAmount : '—' }}</td>
                            <td class="text-end">{{ $group->taxAmount !== '' ? $group->taxAmount : '—' }}</td>
                            <td class="text-end">{{ $group->totalAmount }}</td>
                            <td>{{ $group->paymentMode !== '' ? $group->paymentMode : '—' }}</td>
                            <td>{{ $group->status }}</td>
                            <td>{{ $group->documentType }}</td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="12" class="text-muted">No statutory invoices for the selected date range.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $invoiceGroups->links() }}

    <details class="mt-4">
        <summary class="small text-muted">Full 27-column export contract</summary>
        <p class="small text-muted mb-2">Export retains one row per product line with all columns below.</p>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th class="text-nowrap small">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
            </table>
        </div>
    </details>
@endsection

@push('scripts')
    <script>
        (() => {
            const root = document.getElementById('ca-monthly-report');
            if (!root) {
                return;
            }

            const setExpanded = (button, expanded) => {
                const invoiceId = button.dataset.invoiceId;
                const childRows = root.querySelectorAll(`[data-parent-invoice="${invoiceId}"]`);
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                button.textContent = expanded ? '−' : '+';
                button.title = expanded ? 'Collapse invoice lines' : 'Expand invoice lines';
                childRows.forEach((row) => row.classList.toggle('d-none', !expanded));
            };

            root.querySelectorAll('.ca-monthly-toggle').forEach((button) => {
                button.addEventListener('click', () => {
                    const expanded = button.getAttribute('aria-expanded') === 'true';
                    setExpanded(button, !expanded);
                });

                button.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        const expanded = button.getAttribute('aria-expanded') === 'true';
                        setExpanded(button, !expanded);
                    }
                });
            });
        })();
    </script>
@endpush
