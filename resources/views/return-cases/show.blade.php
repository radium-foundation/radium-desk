@extends('layouts.app')

@section('title', $case->case_no)

@section('content')
    <div class="container py-3 py-md-4" style="max-width: 840px;">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('return-cases.index') }}">Return cases</a></li>
                <li class="breadcrumb-item active">{{ $case->case_no }}</li>
            </ol>
        </nav>

        @if (session('status'))
            <div class="alert alert-success small" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger small" role="alert">
                <p class="mb-1 fw-semibold">Could not save — check the fields below.</p>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1">{{ $case->case_no }}</h1>
                <p class="text-muted small mb-0">Internal staff only — not a customer portal. No tax, refund, or stock release here.</p>
            </div>
            <span class="badge bg-secondary">{{ $case->status->label() }}</span>
        </div>

        @include('return-cases.partials.pos-refund-request', [
            'case' => $case,
            'canSubmitPosRefund' => $canSubmitPosRefund ?? false,
            'canReviewPosRefund' => $canReviewPosRefund ?? false,
        ])

        @include('return-cases.partials.online-refund-request', [
            'case' => $case,
            'canSubmitOnlineRefund' => $canSubmitOnlineRefund ?? false,
        ])

        <div class="card mb-3">
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 col-sm-3">Source</dt>
                    <dd class="col-7 col-sm-9">{{ $case->source_kind->label() }}</dd>
                    <dt class="col-5 col-sm-3">Branch</dt>
                    <dd class="col-7 col-sm-9">{{ $case->branch?->name ?? 'Central / unassigned' }}</dd>
                    <dt class="col-5 col-sm-3">POS sale</dt>
                    <dd class="col-7 col-sm-9">{{ $case->inventory_sale_id ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Order</dt>
                    <dd class="col-7 col-sm-9">{{ $case->order_id ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Fulfilment</dt>
                    <dd class="col-7 col-sm-9">{{ $case->hardware_fulfilment_id ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Reason</dt>
                    <dd class="col-7 col-sm-9">{{ $case->reason }}</dd>
                    @if ($case->requester_remarks)
                        <dt class="col-5 col-sm-3">Internal remarks</dt>
                        <dd class="col-7 col-sm-9">{{ $case->requester_remarks }}</dd>
                    @endif
                    <dt class="col-5 col-sm-3">Requested by</dt>
                    <dd class="col-7 col-sm-9">{{ $case->requester?->name ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Submitted</dt>
                    <dd class="col-7 col-sm-9">{{ $case->created_at?->format('d M Y H:i') }}</dd>
                </dl>
            </div>
        </div>

        <h2 class="h6">Requested items</h2>
        @php
            $receiptsByLine = $case->receipts->keyBy('return_exchange_case_line_id');
            $caseOpen = $case->status !== \App\Enums\ReturnExchangeCaseStatus::Closed;
        @endphp

        <div class="vstack gap-3 mb-4">
            @foreach ($case->lines as $line)
                @php
                    $receipt = $receiptsByLine->get($line->id);
                @endphp
                <div class="card">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <span class="small fw-semibold">Line {{ $line->line_no }} — {{ $line->description }}</span>
                        <span class="badge bg-light text-dark border">Qty {{ $line->qty }}</span>
                    </div>
                    <div class="card-body small">
                        <p class="mb-2">
                            <span class="text-muted">Expected serial:</span>
                            {{ $line->serial_number ?? 'Not specified on line' }}
                        </p>

                        @if ($receipt)
                            <div class="border rounded p-2 mb-2 bg-light">
                                <p class="mb-1 fw-semibold">Receipt recorded</p>
                                <ul class="list-unstyled mb-0">
                                    <li>Received: {{ $receipt->received_at?->format('d M Y H:i') }}
                                        @if ($receipt->receiver)
                                            by {{ $receipt->receiver->name }}
                                        @endif
                                    </li>
                                    <li>Qty received: {{ $receipt->qty_received }}</li>
                                    @if ($receipt->serial_number_scanned)
                                        <li>Serial scan: {{ $receipt->serial_number_scanned }}</li>
                                    @endif
                                    @if ($receipt->serial)
                                        <li>Inventory status: {{ $receipt->serial->status->label() }}</li>
                                    @endif
                                </ul>
                            </div>

                            @if ($receipt->inspected_at)
                                <div class="border rounded p-2 bg-light">
                                    <p class="mb-1 fw-semibold">Inspection complete</p>
                                    <ul class="list-unstyled mb-0">
                                        <li>Inspected: {{ $receipt->inspected_at->format('d M Y H:i') }}
                                            @if ($receipt->inspector)
                                                by {{ $receipt->inspector->name }}
                                            @endif
                                        </li>
                                        <li>Product match: {{ $receipt->product_match_passed ? 'Pass' : 'Fail' }}</li>
                                        <li>Device powers on: {{ $receipt->device_operates_ok ? 'Yes' : 'No' }}</li>
                                        <li>Accessories verified: {{ $receipt->accessories_verified ? 'Yes' : 'No' }}</li>
                                        @if ($receipt->repair_hold)
                                            <li>Repair hold: Yes</li>
                                        @endif
                                        @if ($receipt->condition_code)
                                            <li>Condition: {{ $receipt->condition_code }}</li>
                                        @endif
                                        @if ($receipt->serial_status_after_inspection)
                                            <li>Serial after inspection: {{ $receipt->serial_status_after_inspection }}</li>
                                        @endif
                                        @if ($receipt->internal_notes)
                                            <li class="mt-1"><span class="text-muted">Internal notes:</span> {{ $receipt->internal_notes }}</li>
                                        @endif
                                    </ul>
                                </div>
                            @elseif ($canInspect && $caseOpen)
                                <h3 class="h6 mt-3">Inspect received goods</h3>
                                <form method="POST" action="{{ route('return-cases.receipts.inspect', [$case, $receipt]) }}" class="vstack gap-2">
                                    @csrf
                                    <div>
                                        <label class="form-label">Product / serial match</label>
                                        <select name="product_match_passed" class="form-select form-select-sm" required>
                                            <option value="1" @selected(old('product_match_passed') === '1')>Pass</option>
                                            <option value="0" @selected(old('product_match_passed') === '0')>Fail</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Device powers on</label>
                                        <select name="device_operates_ok" class="form-select form-select-sm" required>
                                            <option value="1" @selected(old('device_operates_ok') === '1')>Yes</option>
                                            <option value="0" @selected(old('device_operates_ok') === '0')>No</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Accessories verified</label>
                                        <select name="accessories_verified" class="form-select form-select-sm" required>
                                            <option value="1" @selected(old('accessories_verified') === '1')>Yes</option>
                                            <option value="0" @selected(old('accessories_verified') === '0')>No</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Condition code (optional)</label>
                                        <input type="text" name="condition_code" class="form-control form-control-sm" maxlength="40"
                                               value="{{ old('condition_code') }}" placeholder="e.g. cosmetic_wear">
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="repair_hold" value="0">
                                        <input type="checkbox" name="repair_hold" value="1" class="form-check-input" id="repair_hold_{{ $receipt->id }}"
                                               @checked(old('repair_hold'))>
                                        <label class="form-check-label" for="repair_hold_{{ $receipt->id }}">Repair hold</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="mark_damaged" value="0">
                                        <input type="checkbox" name="mark_damaged" value="1" class="form-check-input" id="mark_damaged_{{ $receipt->id }}"
                                               @checked(old('mark_damaged'))>
                                        <label class="form-check-label" for="mark_damaged_{{ $receipt->id }}">Mark inventory serial damaged (stays non-sellable)</label>
                                    </div>
                                    <div>
                                        <label class="form-label">Internal notes</label>
                                        <textarea name="internal_notes" class="form-control form-control-sm" rows="2" maxlength="5000">{{ old('internal_notes') }}</textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm w-100 w-md-auto">Save inspection</button>
                                </form>
                            @elseif ($caseOpen)
                                <p class="text-muted mb-0">Inspection will be available after receipt and when you have inspect permission.</p>
                            @endif
                        @elseif ($canReceive && $caseOpen)
                            <h3 class="h6">Receive goods</h3>
                            <form method="POST" action="{{ route('return-cases.receive', $case) }}" class="vstack gap-2">
                                @csrf
                                <input type="hidden" name="return_exchange_case_line_id" value="{{ $line->id }}">
                                <div>
                                    <label class="form-label">Quantity received</label>
                                    <input type="number" name="qty_received" class="form-control form-control-sm" min="1" max="9999"
                                           value="{{ old('return_exchange_case_line_id') == $line->id ? old('qty_received', $line->qty) : $line->qty }}">
                                </div>
                                <div>
                                    <label class="form-label">Serial scan / reference</label>
                                    <input type="text" name="serial_number_scanned" class="form-control form-control-sm" maxlength="128"
                                           value="{{ old('return_exchange_case_line_id') == $line->id ? old('serial_number_scanned') : '' }}"
                                           placeholder="{{ $line->serial_number ? 'Scan: '.$line->serial_number : 'Optional unless serialized POS' }}"
                                           autocomplete="off">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100 w-md-auto">Record receipt</button>
                            </form>
                        @elseif ($caseOpen)
                            <p class="text-muted mb-0">Receipt is not available for your role or branch assignment.</p>
                        @else
                            <p class="text-muted mb-0">This case is closed — receipt and inspection are locked.</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
