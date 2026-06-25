@extends('layouts.app')

@section('title', config('ui.service_case.create_new_action'))

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('orders.index') }}">Orders</a></li>
                <li class="breadcrumb-item"><a href="{{ route('orders.show', $order) }}">{{ $order->order_id }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ config('ui.service_case.create_new_action') }}</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">{{ config('ui.service_case.create_new_action') }}</h1>
        <p class="text-muted mb-0">Add a new service case for order {{ $order->order_id }}.</p>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Order Details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4 text-muted">Customer</dt>
                        <dd class="col-sm-8">{{ $order->customer_name ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Order ID</dt>
                        <dd class="col-sm-8 fw-semibold">{{ $order->order_id }}</dd>

                        <dt class="col-sm-4 text-muted">Product</dt>
                        <dd class="col-sm-8">{{ $order->product_name ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Serial Number</dt>
                        <dd class="col-sm-8">{{ $order->serial_number ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            @include('orders.partials.active-service-case-banner', [
                'order' => $order,
                'activeIncident' => $activeIncident,
                'continueUrl' => '#new-service-case-form',
            ])

            <div class="card border-0 shadow-sm" id="new-service-case-form">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">New Service Case</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('orders.service-cases.store', $order) }}">
                        @csrf

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="source" class="form-label">Source <span class="text-danger">*</span></label>
                                <select name="source" id="source" class="form-select @error('source') is-invalid @enderror" required>
                                    <option value="" disabled @selected(old('source') === null)>Select source</option>
                                    @foreach($enabledSources as $sourceOption)
                                        <option value="{{ $sourceOption->key }}" @selected(old('source') === $sourceOption->key)>
                                            {{ $sourceOption->label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('source')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input type="checkbox"
                                           name="high_priority"
                                           value="1"
                                           id="high_priority"
                                           class="form-check-input @error('high_priority') is-invalid @enderror"
                                           @checked(old('high_priority'))>
                                    <label class="form-check-label" for="high_priority">High Priority</label>
                                    @error('high_priority')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="notes" class="form-label">Problem Description <span class="text-danger">*</span></label>
                                <textarea name="notes" id="notes" rows="5"
                                          class="form-control @error('notes') is-invalid @enderror"
                                          placeholder="Describe the new issue or customer complaint..."
                                          required>{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-1"></i> Create
                            </button>
                            <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">{{ config('ui.service_case.previous_cases_heading') }}</h2>
                </div>
                <div class="card-body p-0">
                    @if($order->incidents->isEmpty())
                        <div class="p-3 text-muted small">{{ config('ui.service_case.history_empty') }}</div>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach($order->incidents as $serviceCase)
                                <li class="list-group-item">
                                    <a href="{{ route('incidents.show', $serviceCase) }}" class="text-decoration-none d-block">
                                        <div class="fw-semibold">{{ $serviceCase->display_reference }}</div>
                                        <div class="small">{{ $serviceCase->issueSummary() }}</div>
                                        <div class="small text-muted mt-1">
                                            {{ $serviceCase->status->label() }}
                                            · {{ display_app_date($serviceCase->created_at) }}
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="card-footer bg-white border-top-0 pb-3">
                    @include('orders.partials.service-case-guidance')
                </div>
            </div>
        </div>
    </div>
@endsection
