@extends('layouts.app')

@section('title', 'New return case')

@section('content')
    <div class="container py-4" style="max-width: 720px;">
        <h1 class="h4 mb-3">New return / exchange case</h1>
        <p class="text-muted small">
            For internal tracking only. No stock, tax, payment, or refund actions are performed when you submit.
        </p>

        <form method="POST" action="{{ route('return-cases.store') }}" class="card card-body">
            @csrf

            <div class="mb-3">
                <label for="source_kind" class="form-label">Source type</label>
                <select name="source_kind" id="source_kind" class="form-select" required>
                    @foreach ($sourceKinds as $kind)
                        <option value="{{ $kind->value }}" @selected(old('source_kind') === $kind->value)>
                            {{ $kind->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="mb-3">
                <label for="inventory_sale_id" class="form-label">POS sale ID</label>
                <input type="number" name="inventory_sale_id" id="inventory_sale_id" class="form-control"
                       value="{{ old('inventory_sale_id') }}" min="1" placeholder="Required for POS sale">
            </div>

            <div class="mb-3">
                <label for="order_id" class="form-label">Desk order ID</label>
                <input type="number" name="order_id" id="order_id" class="form-control"
                       value="{{ old('order_id') }}" min="1" placeholder="Service or ecommerce order">
            </div>

            <div class="mb-3">
                <label for="hardware_fulfilment_id" class="form-label">Hardware fulfilment ID</label>
                <input type="number" name="hardware_fulfilment_id" id="hardware_fulfilment_id" class="form-control"
                       value="{{ old('hardware_fulfilment_id') }}" min="1" placeholder="Ecommerce fulfilment">
            </div>

            <div class="mb-3">
                <label for="idempotency_key" class="form-label">Idempotency key (optional)</label>
                <input type="text" name="idempotency_key" id="idempotency_key" class="form-control"
                       value="{{ old('idempotency_key') }}" maxlength="128">
            </div>

            <div class="mb-3">
                <label for="reason" class="form-label">Reason</label>
                <textarea name="reason" id="reason" class="form-control" rows="3" required>{{ old('reason') }}</textarea>
            </div>

            <div class="mb-3">
                <label for="requester_remarks" class="form-label">Internal remarks</label>
                <textarea name="requester_remarks" id="requester_remarks" class="form-control" rows="2">{{ old('requester_remarks') }}</textarea>
            </div>

            <fieldset class="mb-3 border rounded p-3">
                <legend class="float-none w-auto fs-6 px-1">Line 1</legend>
                <div class="mb-2">
                    <label class="form-label" for="line_description">Description</label>
                    <input type="text" name="lines[0][description]" id="line_description" class="form-control"
                           value="{{ old('lines.0.description') }}" required>
                </div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label" for="line_qty">Qty</label>
                        <input type="number" name="lines[0][qty]" id="line_qty" class="form-control"
                               value="{{ old('lines.0.qty', 1) }}" min="1">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="line_serial">Serial (if applicable)</label>
                        <input type="text" name="lines[0][serial_number]" id="line_serial" class="form-control"
                               value="{{ old('lines.0.serial_number') }}">
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label" for="line_sale_line_id">POS sale line ID (optional)</label>
                    <input type="number" name="lines[0][inventory_sale_line_id]" id="line_sale_line_id" class="form-control"
                           value="{{ old('lines.0.inventory_sale_line_id') }}" min="1">
                </div>
            </fieldset>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Submit case</button>
                <a href="{{ route('return-cases.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
@endsection
