@extends('layouts.app')

@section('title', 'Bank Accounts')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <h1 class="h3 mb-1">Bank Accounts</h1>
        <p class="text-muted mb-0">Bank accounts used for transfers and settlements. No balances stored.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'bank_accounts'])

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Add Bank Account</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('finance.settings.bank-accounts.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="bank_name" class="form-label">Bank Name</label>
                            <input
                                type="text"
                                id="bank_name"
                                name="bank_name"
                                class="form-control @error('bank_name') is-invalid @enderror"
                                value="{{ old('bank_name') }}"
                                required
                                maxlength="255"
                            >
                            @error('bank_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label for="account_name" class="form-label">Account Name</label>
                            <input
                                type="text"
                                id="account_name"
                                name="account_name"
                                class="form-control @error('account_name') is-invalid @enderror"
                                value="{{ old('account_name') }}"
                                required
                                maxlength="255"
                            >
                            @error('account_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label for="last_four" class="form-label">Last 4 Digits</label>
                            <input
                                type="text"
                                id="last_four"
                                name="last_four"
                                class="form-control @error('last_four') is-invalid @enderror"
                                value="{{ old('last_four') }}"
                                required
                                maxlength="4"
                                pattern="[0-9]{4}"
                                inputmode="numeric"
                            >
                            @error('last_four')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add Account</button>
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
                                <th>Account</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($bankAccounts as $bankAccount)
                                <tr>
                                    <td>
                                        @if($bankAccount->is_active)
                                            <span class="badge text-bg-success">Active</span>
                                        @else
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form
                                            method="POST"
                                            action="{{ route('finance.settings.bank-accounts.update', $bankAccount) }}"
                                            class="row g-2 align-items-center"
                                        >
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-4">
                                                <input
                                                    type="text"
                                                    name="bank_name"
                                                    class="form-control form-control-sm"
                                                    value="{{ old('bank_name', $bankAccount->bank_name) }}"
                                                    required
                                                    maxlength="255"
                                                    aria-label="Bank name"
                                                    placeholder="Bank name"
                                                >
                                            </div>
                                            <div class="col-md-4">
                                                <input
                                                    type="text"
                                                    name="account_name"
                                                    class="form-control form-control-sm"
                                                    value="{{ old('account_name', $bankAccount->account_name) }}"
                                                    required
                                                    maxlength="255"
                                                    aria-label="Account name"
                                                    placeholder="Account name"
                                                >
                                            </div>
                                            <div class="col-md-2">
                                                <input
                                                    type="text"
                                                    name="last_four"
                                                    class="form-control form-control-sm"
                                                    value="{{ old('last_four', $bankAccount->last_four) }}"
                                                    required
                                                    maxlength="4"
                                                    pattern="[0-9]{4}"
                                                    inputmode="numeric"
                                                    aria-label="Last four digits"
                                                    placeholder="Last 4"
                                                >
                                            </div>
                                            <div class="col-md-2">
                                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Save</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <form
                                            method="POST"
                                            action="{{ route('finance.settings.bank-accounts.toggle', $bankAccount) }}"
                                            class="d-inline"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                {{ $bankAccount->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-muted text-center py-4">No bank accounts configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0">No balances or reconciliation in this phase.</p>
        </div>
    </div>
@endsection
