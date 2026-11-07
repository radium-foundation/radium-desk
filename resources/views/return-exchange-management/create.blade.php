@extends('layouts.app')

@section('title', 'New hybrid return case')

@section('content')
    <div class="container py-4" style="max-width: 920px;">
        <h1 class="h4 mb-3">Create hybrid return / exchange case</h1>

        <form method="GET" class="card card-body mb-3 small" id="pos-sale-search">
            <h2 class="h6">Find POS sale</h2>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Sale number</label>
                    <input type="text" name="sale_no" class="form-control form-control-sm" value="{{ request('sale_no') }}" placeholder="POS-6754">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Load sale</button>
                </div>
            </div>
            @if ($salePayload)
                <div class="mt-3 border rounded p-2 bg-light">
                    <p class="mb-1"><strong>{{ $salePayload['sale_no'] }}</strong> — {{ $salePayload['status'] }}</p>
                    <p class="mb-1">Branch: {{ $salePayload['branch'] ?? '—' }}</p>
                    <p class="mb-2">Invoice: {{ $salePayload['invoice'] ?? '—' }} ({{ $salePayload['invoice_status'] ?? '—' }})</p>
                    <p class="mb-1 fw-semibold">Sold serials (select one to return)</p>
                    <ul class="mb-0">
                        @foreach ($salePayload['serials'] as $row)
                            <li>{{ $row['serial_number'] }} — {{ $row['sku'] }} ({{ $row['status'] }})</li>
                        @endforeach
                    </ul>
                </div>
            @elseif (request()->filled('sale_no'))
                <p class="text-danger small mt-2 mb-0">Sale not found.</p>
            @endif
        </form>

        @if ($sale)
            <form method="POST" action="{{ route('return-exchange-management.store') }}" class="card card-body">
                @csrf
                <input type="hidden" name="inventory_sale_id" value="{{ $sale->id }}">

                <div class="mb-3">
                    <label class="form-label">Replacement category</label>
                    <select name="replacement_category" class="form-select" required>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->value }}" @selected(old('replacement_category', 'customer_requested_exchange') === $cat->value)>
                                {{ $cat->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="form-text">Do not choose warranty replacement without evidence.</p>
                </div>

                <div class="mb-3">
                    <label class="form-label">Return serial on this sale</label>
                    <select name="lines[0][serial_number]" class="form-select" required>
                        <option value="">Select…</option>
                        @foreach ($sale->serials as $assignment)
                            <option value="{{ $assignment->serial?->serial_number }}" @selected(old('lines.0.serial_number') === $assignment->serial?->serial_number)>
                                {{ $assignment->serial?->serial_number }} ({{ $assignment->serial?->product?->sku }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <input type="hidden" name="lines[0][description]" value="Return unit from POS {{ $sale->sale_no }}">
                <input type="hidden" name="lines[0][qty]" value="1">

                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" required>{{ old('reason') }}</textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Customer request summary</label>
                    <textarea name="customer_request_summary" class="form-control" rows="2">{{ old('customer_request_summary') }}</textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Connector / variant change (e.g. USB → Type-C)</label>
                    <input type="text" name="requested_connector_change" class="form-control" value="{{ old('requested_connector_change') }}">
                </div>

                <div class="mb-3">
                    <label class="form-label">Supporting evidence / internal notes</label>
                    <textarea name="hybrid_workflow_notes" class="form-control" rows="2">{{ old('hybrid_workflow_notes') }}</textarea>
                </div>

                <button type="submit" class="btn btn-primary">Create case (intake only)</button>
                <a href="{{ route('return-exchange-management.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        @else
            <p class="text-muted small">Load a POS sale before creating a case.</p>
        @endif
    </div>
@endsection
