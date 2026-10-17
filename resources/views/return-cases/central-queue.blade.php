@extends('layouts.app')

@section('title', 'Central return-case queue')

@section('content')
    <div class="container py-3 py-md-4" style="max-width: 960px;">
        <div class="mb-3">
            <h1 class="h4 mb-1">Central return-case queue</h1>
            <p class="text-muted small mb-0">
                Read-only triage for Finance and central ops. No tax, refund, stock release, or customer actions on this screen.
            </p>
        </div>

        <form method="GET" action="{{ route('return-cases.central-queue') }}" class="card card-body mb-3 small">
            <div class="row g-2">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="status">Case status</label>
                    <select name="status" id="status" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="source_kind">Source</label>
                    <select name="source_kind" id="source_kind" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($sourceKinds as $kind)
                            <option value="{{ $kind->value }}" @selected(($filters['source_kind'] ?? null) === $kind->value)>
                                {{ $kind->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select name="branch_id" id="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="submitted_from">Submitted from</label>
                    <input type="date" name="submitted_from" id="submitted_from" class="form-control form-control-sm"
                           value="{{ $filters['submitted_from'] ?? '' }}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="submitted_to">Submitted to</label>
                    <input type="date" name="submitted_to" id="submitted_to" class="form-control form-control-sm"
                           value="{{ $filters['submitted_to'] ?? '' }}">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="receipt_state">Receipt / inspection</label>
                    <select name="receipt_state" id="receipt_state" class="form-select form-select-sm">
                        @foreach ([
                            'any' => 'Any receipt state',
                            'unreceived' => 'Nothing received yet',
                            'partial' => 'Partially received',
                            'awaiting_inspection' => 'Received — inspection pending',
                            'fully_inspected' => 'Fully inspected',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['receipt_state'] ?? 'any') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
                    <a href="{{ route('return-cases.central-queue') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                </div>
            </div>
            @if ($errors->any())
                <div class="alert alert-warning mt-2 mb-0 py-2" role="alert">
                    Check filter values: {{ $errors->first() }}
                </div>
            @endif
        </form>

        @if ($filtersActive && $cases->isEmpty())
            <div class="alert alert-secondary small" role="status">
                No cases match the current filters. Try clearing filters or widening the date range.
            </div>
        @endif

        <div class="vstack gap-2">
            @forelse ($cases as $case)
                @php
                    $receipts = $case->receipts;
                    $linesCount = (int) $case->lines_count;
                    $receiptsCount = (int) $case->receipts_count;
                    $pendingInspection = $receipts->contains(fn ($r) => $r->inspected_at === null);
                    $invoiceNo = $case->inventorySale?->statutoryInvoice?->invoice_number;
                    $refundCount = $case->order_id ? (int) ($case->order?->refund_requests_count ?? 0) : null;
                @endphp
                <div class="card">
                    <div class="card-body small">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                            <div>
                                <a href="{{ route('return-cases.show', $case) }}" class="fw-semibold text-decoration-none">
                                    {{ $case->case_no }}
                                </a>
                                <span class="text-muted">· {{ $case->source_kind->label() }}</span>
                            </div>
                            <span class="badge bg-secondary">{{ $case->status->label() }}</span>
                        </div>
                        <dl class="row mb-2">
                            <dt class="col-5 col-sm-3">Branch</dt>
                            <dd class="col-7 col-sm-9">{{ $case->branch?->name ?? 'Central / unassigned' }}</dd>
                            <dt class="col-5 col-sm-3">Submitted</dt>
                            <dd class="col-7 col-sm-9">{{ $case->created_at?->format('d M Y H:i') }}
                                <span class="text-muted">({{ $case->created_at?->diffForHumans() }})</span>
                            </dd>
                            <dt class="col-5 col-sm-3">References</dt>
                            <dd class="col-7 col-sm-9">
                                @if ($case->inventory_sale_id)
                                    POS sale #{{ $case->inventory_sale_id }}
                                    @if ($invoiceNo)
                                        · Invoice {{ $invoiceNo }}
                                    @endif
                                @elseif ($case->order_id)
                                    Order #{{ $case->order_id }}
                                    @if ($case->order?->order_id)
                                        ({{ $case->order->order_id }})
                                    @endif
                                    @if ($refundCount !== null && $refundCount > 0)
                                        · {{ $refundCount }} linked refund request(s)
                                    @endif
                                @elseif ($case->hardware_fulfilment_id)
                                    Fulfilment #{{ $case->hardware_fulfilment_id }}
                                @else
                                    —
                                @endif
                            </dd>
                            @if ($case->refundRequest)
                                <dt class="col-5 col-sm-3">Online refund request</dt>
                                <dd class="col-7 col-sm-9">
                                    <a href="{{ route('refunds.show', $case->refundRequest) }}">{{ $case->refundRequest->reference_no }}</a>
                                    · {{ $case->refundRequest->status->label() }}
                                    · ₹{{ number_format((float) $case->refundRequest->refund_amount, 2) }}
                                </dd>
                            @endif
                            @if ($case->posRefundRequest)
                                <dt class="col-5 col-sm-3">POS refund request</dt>
                                <dd class="col-7 col-sm-9">
                                    {{ $case->posRefundRequest->reference_no }}
                                    · {{ $case->posRefundRequest->status->label() }}
                                    @if ($case->posRefundRequest->reviewed_at)
                                        <br><span class="text-muted">Decided {{ $case->posRefundRequest->reviewed_at->format('d M Y H:i') }}
                                            @if ($case->posRefundRequest->reviewer)
                                                by {{ $case->posRefundRequest->reviewer->name }}
                                            @endif
                                        </span>
                                    @endif
                                </dd>
                            @endif
                            <dt class="col-5 col-sm-3">Receipt</dt>
                            <dd class="col-7 col-sm-9">
                                @if ($receiptsCount === 0)
                                    Not received
                                @elseif ($receiptsCount < $linesCount)
                                    Partial ({{ $receiptsCount }}/{{ $linesCount }} lines)
                                @elseif ($pendingInspection)
                                    Received — inspection pending
                                @else
                                    Fully inspected
                                @endif
                            </dd>
                        </dl>
                        <p class="mb-1 fw-semibold">Items</p>
                        <ul class="list-unstyled mb-0">
                            @foreach ($case->lines as $line)
                                @php
                                    $lineReceipt = $receipts->firstWhere('return_exchange_case_line_id', $line->id);
                                @endphp
                                <li class="mb-1 border-bottom border-light pb-1">
                                    Line {{ $line->line_no }}: {{ $line->description }} (qty {{ $line->qty }})
                                    @if ($line->serial_number)
                                        <br><span class="text-muted">Expected serial:</span> {{ $line->serial_number }}
                                    @endif
                                    @if ($lineReceipt)
                                        <br><span class="text-muted">Received:</span>
                                        qty {{ $lineReceipt->qty_received }}
                                        @if ($lineReceipt->serial_number_scanned)
                                            · scan {{ $lineReceipt->serial_number_scanned }}
                                        @endif
                                        @if ($lineReceipt->serial)
                                            · stock {{ $lineReceipt->serial->status->label() }}
                                        @endif
                                        @if ($lineReceipt->inspected_at)
                                            · match {{ $lineReceipt->product_match_passed ? 'pass' : 'fail' }}
                                            · powers on {{ $lineReceipt->device_operates_ok ? 'yes' : 'no' }}
                                            @if ($lineReceipt->repair_hold)
                                                · repair hold
                                            @endif
                                        @endif
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @empty
                @unless ($filtersActive)
                    <div class="alert alert-light border small mb-0" role="status">
                        No return cases have been submitted yet.
                    </div>
                @endunless
            @endforelse
        </div>

        <div class="mt-3">
            {{ $cases->links() }}
        </div>
    </div>
@endsection
