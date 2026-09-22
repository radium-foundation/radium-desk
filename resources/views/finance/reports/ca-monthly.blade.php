@extends('layouts.app')

@section('title', 'CA Monthly Report')

@section('content')
    @php
        $periodLabel = ($filters['date_from'] ?? '') !== '' && ($filters['date_to'] ?? '') !== ''
            ? \Illuminate\Support\Carbon::parse($filters['date_from'])->format('j M Y').' – '.\Illuminate\Support\Carbon::parse($filters['date_to'])->format('j M Y')
            : 'Select a reporting period';
        $xlsxExportUrl = route('finance.reports.ca-monthly.export.xlsx', request()->query());
        $csvExportUrl = route('finance.reports.ca-monthly.export.csv', request()->query());
    @endphp

    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Reports</p>
        <h1 class="h3 mb-2">CA Monthly Report</h1>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="badge rounded-pill text-bg-primary fs-6 fw-normal">{{ $periodLabel }}</span>
        </div>
        <p class="text-muted mb-0 small">
            Statutory invoice export for CA review. Reporting period uses
            <strong>Date of Invoice</strong> (<code>statutory_invoices.issued_at</code>).
            Preview groups multi-product invoices; export retains one row per product line in the 27-column contract.
        </p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'ca_monthly_report'])

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">Reporting period</h2>
            <form method="get" action="{{ route('finance.reports.ca-monthly.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small text-muted mb-1" for="date_from">From date</label>
                    <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control" required>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small text-muted mb-1" for="date_to">To date</label>
                    <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control" required>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-outline-secondary w-100">Update period</button>
                </div>
            </form>
        </div>
    </div>

    @include('finance.reports.partials.ca-monthly-preflight', ['preflight' => $preflight])

    @if (session('status'))
        <div class="alert alert-info py-2 small">{{ session('status') }}</div>
    @endif

    @if ($canExport)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 mb-1">Export report</h2>
                <p class="small text-muted mb-3">
                    Choose Excel or CSV. Optional email delivery is available.
                    Estimated {{ number_format($estimatedExportLines) }} export line(s);
                    reports above {{ number_format($asyncExportThreshold) }} lines are prepared in the background.
                </p>

                <form method="post" action="{{ route('finance.reports.ca-monthly.exports.store') }}" class="row g-3 align-items-end" id="ca-monthly-export-form">
                    @csrf
                    <input type="hidden" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                    <input type="hidden" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                    <div class="col-12 col-md-3">
                        <label class="form-label small text-muted mb-1" for="export_format">File format</label>
                        <select id="export_format" name="format" class="form-select" required>
                            <option value="xlsx">Excel (.xlsx)</option>
                            <option value="csv">CSV (.csv)</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label small text-muted mb-1" for="email_recipient">Email report (optional)</label>
                        <input type="email" id="email_recipient" name="email_recipient" value="{{ auth()->user()?->email }}" class="form-control" placeholder="finance@example.com" autocomplete="email">
                    </div>
                    <div class="col-12 col-md-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">Export Report</button>
                        <a href="{{ $xlsxExportUrl }}" class="btn btn-outline-secondary ca-monthly-quick-download" data-format="xlsx" id="ca-monthly-quick-download">Download now</a>
                    </div>
                </form>
            </div>
        </div>

        @if ($recentExports->isNotEmpty())
            <div class="card border-0 shadow-sm mb-3" id="ca-monthly-exports">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h2 class="h6 mb-0">Recent exports</h2>
                        <span class="small text-muted">Background exports and emailed deliveries</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 ca-monthly-exports-table">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-nowrap">Date range</th>
                                    <th class="text-nowrap">Format</th>
                                    <th class="text-nowrap">Status</th>
                                    <th class="text-nowrap text-end">Rows</th>
                                    <th class="text-nowrap">Email</th>
                                    <th class="text-nowrap text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentExports as $export)
                                    <tr data-export-id="{{ $export->id }}" data-export-status="{{ $export->status->value }}">
                                        <td class="small fw-medium">{{ $export->dateRangeLabel() }}</td>
                                        <td class="small"><span class="badge text-bg-light border">{{ $export->format->label() }}</span></td>
                                        <td class="small">
                                            @php
                                                $statusClass = match ($export->status->value) {
                                                    'ready' => 'text-bg-success',
                                                    'failed' => 'text-bg-danger',
                                                    'processing' => 'text-bg-primary',
                                                    default => 'text-bg-secondary',
                                                };
                                            @endphp
                                            <span class="badge {{ $statusClass }} export-status-label">{{ $export->status->label() }}</span>
                                            @if ($export->failure_message)
                                                <div class="text-danger mt-1">{{ $export->failure_message }}</div>
                                            @endif
                                        </td>
                                        <td class="small text-end export-row-count">{{ $export->row_count ?? '—' }}</td>
                                        <td class="small">
                                            @if ($export->email_recipient)
                                                <div>{{ $export->email_recipient }}</div>
                                                @if ($export->email_status)
                                                    <div class="text-muted">{{ $export->email_status->label() }}
                                                        @if ($export->email_delivery_mode)
                                                            · {{ $export->email_delivery_mode->label() }}
                                                        @endif
                                                    </div>
                                                @endif
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            @if ($export->isDownloadable())
                                                <a href="{{ route('finance.reports.ca-monthly.exports.download', $export) }}" class="btn btn-sm btn-outline-primary">Download</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-0">
            <div class="px-3 py-3 border-bottom">
                <h2 class="h6 mb-1">Invoice preview</h2>
                <p class="small text-muted mb-0">Paginated statutory invoice summary for the selected period.</p>
            </div>

            <style>
                .ca-monthly-table {
                    font-size: 0.875rem;
                }

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

                @media (max-width: 767.98px) {
                    .ca-monthly-table th,
                    .ca-monthly-table td {
                        white-space: normal;
                    }
                }
            </style>

            <div class="table-responsive" id="ca-monthly-report">
                <table class="table table-sm table-hover align-middle ca-monthly-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-nowrap" style="width:2.5rem;"></th>
                            <th scope="col" class="text-nowrap">Invoice No.</th>
                            <th scope="col" class="text-nowrap">Date of Invoice</th>
                            <th scope="col" class="text-nowrap">Customer</th>
                            <th scope="col" class="text-nowrap d-none d-md-table-cell">Ordertype</th>
                            <th scope="col" class="text-nowrap text-end">Taxable</th>
                            <th scope="col" class="text-nowrap text-end d-none d-lg-table-cell">Shipping</th>
                            <th scope="col" class="text-nowrap text-end d-none d-lg-table-cell">Tax</th>
                            <th scope="col" class="text-nowrap text-end">Total</th>
                            <th scope="col" class="text-nowrap d-none d-xl-table-cell">Payment</th>
                            <th scope="col" class="text-nowrap d-none d-md-table-cell">Status</th>
                            <th scope="col" class="text-nowrap d-none d-xl-table-cell">Document Type</th>
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
                                    <td class="text-nowrap">{{ $group->issuedDate }}</td>
                                    <td>{{ $group->buyerName !== '' ? $group->buyerName : '—' }}</td>
                                    <td class="d-none d-md-table-cell">{{ $group->orderType !== '' ? $group->orderType : '—' }}</td>
                                    <td class="text-end">{{ $group->taxableAmount }}</td>
                                    <td class="text-end d-none d-lg-table-cell">{{ $group->shippingAmount !== '' ? $group->shippingAmount : '—' }}</td>
                                    <td class="text-end d-none d-lg-table-cell">{{ $group->taxAmount !== '' ? $group->taxAmount : '—' }}</td>
                                    <td class="text-end">{{ $group->totalAmount }}</td>
                                    <td class="d-none d-xl-table-cell">{{ $group->paymentMode !== '' ? $group->paymentMode : '—' }}</td>
                                    <td class="d-none d-md-table-cell">{{ $group->status }}</td>
                                    <td class="d-none d-xl-table-cell">{{ $group->documentType }}</td>
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
                                        <td class="text-end small d-none d-lg-table-cell">—</td>
                                        <td class="text-end small d-none d-lg-table-cell">
                                            @php
                                                $lineTax = array_filter([$child->igst, $child->cgst, $child->sgst]);
                                            @endphp
                                            {{ $lineTax !== [] ? implode(' / ', $lineTax) : '—' }}
                                        </td>
                                        <td class="text-end small">—</td>
                                        <td class="small text-muted d-none d-xl-table-cell">Qty {{ $child->quantity }} · {{ $child->hsnSac !== '' ? $child->hsnSac : '—' }}</td>
                                        <td colspan="2" class="d-none d-md-table-cell"></td>
                                    </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td></td>
                                    <td>{{ $group->invoiceNumber }}</td>
                                    <td class="text-nowrap">{{ $group->issuedDate }}</td>
                                    <td>
                                        {{ $group->buyerName !== '' ? $group->buyerName : '—' }}
                                        @if (($group->children[0] ?? null) !== null)
                                            <div class="small text-muted">{{ $group->children[0]->productName }}</div>
                                        @endif
                                    </td>
                                    <td class="d-none d-md-table-cell">{{ $group->orderType !== '' ? $group->orderType : '—' }}</td>
                                    <td class="text-end">{{ $group->taxableAmount }}</td>
                                    <td class="text-end d-none d-lg-table-cell">{{ $group->shippingAmount !== '' ? $group->shippingAmount : '—' }}</td>
                                    <td class="text-end d-none d-lg-table-cell">{{ $group->taxAmount !== '' ? $group->taxAmount : '—' }}</td>
                                    <td class="text-end">{{ $group->totalAmount }}</td>
                                    <td class="d-none d-xl-table-cell">{{ $group->paymentMode !== '' ? $group->paymentMode : '—' }}</td>
                                    <td class="d-none d-md-table-cell">{{ $group->status }}</td>
                                    <td class="d-none d-xl-table-cell">{{ $group->documentType }}</td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="12" class="text-muted p-3">No statutory invoices for the selected date range.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-3 py-2 border-top">
                {{ $invoiceGroups->links() }}
            </div>
        </div>
    </div>

    <details class="mt-2">
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
            const formatSelect = document.getElementById('export_format');
            const quickDownload = document.getElementById('ca-monthly-quick-download');
            const exportUrls = {
                xlsx: @json($xlsxExportUrl),
                csv: @json($csvExportUrl),
            };

            const syncQuickDownload = () => {
                if (!formatSelect || !quickDownload) {
                    return;
                }

                const format = formatSelect.value === 'csv' ? 'csv' : 'xlsx';
                quickDownload.href = exportUrls[format];
                quickDownload.textContent = format === 'csv' ? 'Download now (CSV)' : 'Download now (Excel)';
            };

            formatSelect?.addEventListener('change', syncQuickDownload);
            syncQuickDownload();

            const exportsRoot = document.getElementById('ca-monthly-exports');
            if (exportsRoot) {
                const poll = () => {
                    exportsRoot.querySelectorAll('[data-export-status="queued"], [data-export-status="processing"]').forEach((row) => {
                        const exportId = row.dataset.exportId;
                        fetch(`{{ url('/finance/reports/ca-monthly/exports') }}/${exportId}`, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        })
                            .then((response) => response.ok ? response.json() : null)
                            .then((payload) => {
                                if (!payload) {
                                    return;
                                }

                                row.dataset.exportStatus = payload.status;
                                const statusLabel = row.querySelector('.export-status-label');
                                if (statusLabel) {
                                    statusLabel.textContent = payload.status_label;
                                }

                                const rowCount = row.querySelector('.export-row-count');
                                if (rowCount && payload.row_count !== null) {
                                    rowCount.textContent = payload.row_count;
                                }

                                if (payload.download_url && !row.querySelector('a[href="' + payload.download_url + '"]')) {
                                    const cell = row.querySelector('td:last-child');
                                    if (cell) {
                                        cell.innerHTML = `<a href="${payload.download_url}" class="btn btn-sm btn-outline-primary">Download</a>`;
                                    }
                                }
                            });
                    });
                };

                window.setInterval(poll, 5000);
                poll();
            }

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
