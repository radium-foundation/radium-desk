@extends('layouts.app')

@section('title', 'Payment Methods')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <h1 class="h3 mb-1">Payment Methods</h1>
        <p class="text-muted mb-0">Methods available for customer payments, expenses, and vendor payouts.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'payment_methods'])

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Add Payment Method</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('finance.settings.payment-methods.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="payment_method_name" class="form-label">Name</label>
                            <input
                                type="text"
                                id="payment_method_name"
                                name="name"
                                class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name') }}"
                                required
                                maxlength="255"
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add Method</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Status</th>
                                <th>Name</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($paymentMethods as $paymentMethod)
                                <tr>
                                    <td>
                                        @if($paymentMethod->is_active)
                                            <span class="badge text-bg-success">Active</span>
                                        @else
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form
                                            method="POST"
                                            action="{{ route('finance.settings.payment-methods.update', $paymentMethod) }}"
                                            id="payment-method-form-{{ $paymentMethod->id }}"
                                            class="d-flex gap-2"
                                        >
                                            @csrf
                                            @method('PUT')
                                            <input
                                                type="text"
                                                name="name"
                                                class="form-control form-control-sm"
                                                value="{{ old('name', $paymentMethod->name) }}"
                                                required
                                                maxlength="255"
                                            >
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <form
                                            method="POST"
                                            action="{{ route('finance.settings.payment-methods.toggle', $paymentMethod) }}"
                                            class="d-inline"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                {{ $paymentMethod->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-muted text-center py-4">No payment methods configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0">Deactivating hides a method from new entries. Existing records keep their values.</p>
        </div>
    </div>
@endsection
