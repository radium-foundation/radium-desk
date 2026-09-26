@extends('layouts.app')

@section('title', 'CA Monthly Report')

@section('content')
    @php
        $periodLabel = ($filters['date_from'] ?? '') !== '' && ($filters['date_to'] ?? '') !== ''
            ? \Illuminate\Support\Carbon::parse($filters['date_from'])->format('j M Y').' – '.\Illuminate\Support\Carbon::parse($filters['date_to'])->format('j M Y')
            : 'Select a reporting period';
        $xlsxExportUrl = route('finance.reports.ca-monthly.export.xlsx', request()->query());
        $csvExportUrl = route('finance.reports.ca-monthly.export.csv', request()->query());
        $quickDownloadDisabled = ($showPreflight ?? false) || ($requiresAsyncExport ?? false);
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
            Preview groups multi-product invoices; Excel export is one invoice summary row with expandable non-zero line detail.
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

    @if ($showDownloadHistory ?? false)
        <div id="ca-monthly-download-history-root" class="mb-3" data-download-history-url="{{ route('finance.reports.ca-monthly.download-history', request()->query()) }}">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-1">Report Download History</h2>
                    <p class="small text-muted mb-2">Super Admin audit of CA Monthly report exports and downloads.</p>
                    <div class="d-flex align-items-center gap-2 text-muted small" id="ca-monthly-download-history-loading">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span>Loading download history…</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showPreflight)
        <div id="ca-monthly-preflight-root" class="mb-3" data-preflight-url="{{ route('finance.reports.ca-monthly.preflight', request()->query()) }}">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-1">Preflight summary</h2>
                    <p class="small text-muted mb-2">Validation and totals for the selected reporting period.</p>
                    <div class="d-flex align-items-center gap-2 text-muted small" id="ca-monthly-preflight-loading">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span>Running preflight validation…</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if (session('status'))
        <div class="alert alert-info py-2 small">{{ session('status') }}</div>
    @endif

    @if ($canExport)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 mb-1">Export report</h2>
                <p class="small text-muted mb-3">
                    Choose Excel or CSV. Optional email delivery is available.
                    Estimated {{ number_format($estimatedExportLines) }} invoice(s);
                    reports above {{ number_format($asyncExportThreshold) }} invoices are prepared in the background.
                </p>

                <div class="alert alert-secondary py-2 small d-none" id="ca-monthly-export-processing" role="status">
                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                    <span id="ca-monthly-export-processing-text">Preparing export…</span>
                </div>

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
                        <button type="submit" class="btn btn-primary" id="ca-monthly-export-submit">Export Report</button>
                        <a
                            href="{{ $xlsxExportUrl }}"
                            class="btn btn-outline-secondary ca-monthly-quick-download @if($quickDownloadDisabled) disabled @endif"
                            data-format="xlsx"
                            id="ca-monthly-quick-download"
                            @if($quickDownloadDisabled) aria-disabled="true" tabindex="-1" @endif
                        >Download now</a>
                    </div>
                </form>
                @if ($requiresAsyncExport)
                    <p class="small text-muted mb-0 mt-2" id="ca-monthly-async-hint">
                        This period exceeds the synchronous threshold. Use <strong>Export Report</strong>; download becomes available when background generation completes.
                    </p>
                @endif
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
                            <th scope="col" class="text-nowrap d-none d-xl-table-cell">Channel</th>
                            <th scope="col" class="text-nowrap d-none d-xxl-table-cell">Method</th>
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
                                    <td class="d-none d-xl-table-cell">{{ $group->paymentChannel !== '' ? $group->paymentChannel : '—' }}</td>
                                    <td class="d-none d-xxl-table-cell">{{ $group->paymentMode !== '' ? $group->paymentMode : '—' }}</td>
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
                                        <td class="text-end small d-none d-lg-table-cell">{{ $child->shipping !== '' ? $child->shipping : '—' }}</td>
                                        <td class="text-end small d-none d-lg-table-cell">
                                            @php
                                                $lineTax = array_filter([$child->igst, $child->cgst, $child->sgst]);
                                            @endphp
                                            {{ $lineTax !== [] ? implode(' / ', $lineTax) : '—' }}
                                        </td>
                                        <td class="text-end small">{{ $child->lineTotal !== '' ? $child->lineTotal : '—' }}</td>
                                        <td class="small text-muted d-none d-xl-table-cell" colspan="2">Qty {{ $child->quantity }} · {{ $child->hsnSac !== '' ? $child->hsnSac : '—' }}</td>
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
                                    <td class="d-none d-xl-table-cell">{{ $group->paymentChannel !== '' ? $group->paymentChannel : '—' }}</td>
                                    <td class="d-none d-xxl-table-cell">{{ $group->paymentMode !== '' ? $group->paymentMode : '—' }}</td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="11" class="text-muted p-3">No statutory invoices for the selected date range.</td>
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
        <summary class="small text-muted">Invoice-level export contract</summary>
        <p class="small text-muted mb-2">Excel export uses one invoice summary row per statutory invoice. Multi-line invoices include expandable non-zero line detail rows grouped beneath the parent.</p>
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
            const exportSubmit = document.getElementById('ca-monthly-export-submit');
            const processingBanner = document.getElementById('ca-monthly-export-processing');
            const processingText = document.getElementById('ca-monthly-export-processing-text');
            const requiresAsyncExport = @json($requiresAsyncExport);
            const showPreflight = @json($showPreflight);
            const activeExportId = @json($activeExportId);
            let preflightComplete = !showPreflight;
            let exportInProgress = false;
            const exportUrls = {
                xlsx: @json($xlsxExportUrl),
                csv: @json($csvExportUrl),
            };

            const setQuickDownloadEnabled = (enabled) => {
                if (!quickDownload) {
                    return;
                }

                if (enabled && !requiresAsyncExport && !exportInProgress && preflightComplete) {
                    quickDownload.classList.remove('disabled');
                    quickDownload.removeAttribute('aria-disabled');
                    quickDownload.removeAttribute('tabindex');
                    return;
                }

                quickDownload.classList.add('disabled');
                quickDownload.setAttribute('aria-disabled', 'true');
                quickDownload.setAttribute('tabindex', '-1');
            };

            const setExportControlsEnabled = (enabled) => {
                if (exportSubmit) {
                    exportSubmit.disabled = !enabled;
                }

                setQuickDownloadEnabled(enabled);
            };

            const setProcessingState = (active, message = 'Preparing export…') => {
                exportInProgress = active;
                if (processingBanner) {
                    processingBanner.classList.toggle('d-none', !active);
                }
                if (processingText) {
                    processingText.textContent = message;
                }
                setExportControlsEnabled(!active && preflightComplete);
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

            const preflightRoot = document.getElementById('ca-monthly-preflight-root');
            if (!preflightRoot) {
                setExportControlsEnabled(!exportInProgress);
            } else {
                setExportControlsEnabled(false);
            }

            if (preflightRoot) {
                const preflightUrl = preflightRoot.dataset.preflightUrl;
                fetch(preflightUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then((response) => response.ok ? response.json() : null)
                    .then((payload) => {
                        if (!payload?.html) {
                            return;
                        }

                        preflightRoot.innerHTML = payload.html;
                        preflightComplete = true;
                        setExportControlsEnabled(!exportInProgress);
                    })
                    .catch(() => {
                        preflightRoot.innerHTML = '<div class="alert alert-warning small mb-0">Preflight validation could not be loaded. Refresh the page or contact support.</div>';
                        preflightComplete = true;
                        setExportControlsEnabled(!exportInProgress);
                    });
            }

            @if ($showDownloadHistory ?? false)
            const downloadHistoryRoot = document.getElementById('ca-monthly-download-history-root');
            if (downloadHistoryRoot) {
                const downloadHistoryUrl = downloadHistoryRoot.dataset.downloadHistoryUrl;
                fetch(downloadHistoryUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then((response) => response.ok ? response.json() : null)
                    .then((payload) => {
                        if (!payload?.html) {
                            return;
                        }

                        downloadHistoryRoot.innerHTML = payload.html;
                    })
                    .catch(() => {
                        downloadHistoryRoot.innerHTML = '<div class="alert alert-warning small mb-0">Download history could not be loaded. Refresh the page or contact support.</div>';
                    });
            }
            @endif

            const exportsRoot = document.getElementById('ca-monthly-exports');
            const pollExportRow = (row) => {
                const exportId = row.dataset.exportId;
                fetch(`{{ url('/finance/reports/ca-monthly/exports') }}/${exportId}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then((response) => response.ok ? response.json() : null)
                    .then((payload) => {
                        if (!payload) {
                            return;
                        }

                        const wasPending = ['queued', 'processing'].includes(row.dataset.exportStatus);
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

                        if (wasPending && payload.status === 'ready') {
                            setProcessingState(false);
                        }

                        if (wasPending && payload.status === 'failed') {
                            setProcessingState(false);
                        }
                    });
            };

            if (exportsRoot) {
                const poll = () => {
                    const pendingRows = exportsRoot.querySelectorAll('[data-export-status="queued"], [data-export-status="processing"]');
                    if (pendingRows.length > 0) {
                        setProcessingState(true, `Background export in progress (${pendingRows.length} active)…`);
                    }

                    pendingRows.forEach(pollExportRow);
                };

                window.setInterval(poll, 5000);
                poll();
            }

            if (activeExportId && exportsRoot) {
                const activeRow = exportsRoot.querySelector(`[data-export-id="${activeExportId}"]`);
                if (activeRow && ['queued', 'processing'].includes(activeRow.dataset.exportStatus)) {
                    setProcessingState(true, 'Background export in progress…');
                    pollExportRow(activeRow);
                }
            }

            document.getElementById('ca-monthly-export-form')?.addEventListener('submit', () => {
                setProcessingState(true, requiresAsyncExport
                    ? 'Export queued. Download will appear in Recent exports when generation completes.'
                    : 'Generating export…');
            });

            quickDownload?.addEventListener('click', (event) => {
                if (quickDownload.classList.contains('disabled')) {
                    event.preventDefault();
                }
            });

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
