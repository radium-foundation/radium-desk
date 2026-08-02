@extends('layouts.app')

@section('title', 'Financial Preferences')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <h1 class="h3 mb-1">Financial Preferences</h1>
        <p class="text-muted mb-0">Ledger cutover and default posting accounts.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'financial_preferences'])

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('finance.settings.financial-preferences.update') }}" class="row g-3">
                @csrf
                @method('PUT')

                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="ledger_posting_enabled" name="ledger_posting_enabled" value="1" @checked(old('ledger_posting_enabled', $settings['ledger_posting_enabled']) === '1')>
                        <label class="form-check-label" for="ledger_posting_enabled">Ledger posting enabled</label>
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="ledger_cutover_date" class="form-label">Cutover date</label>
                    <input type="date" id="ledger_cutover_date" name="ledger_cutover_date" class="form-control" value="{{ old('ledger_cutover_date', $settings['ledger_cutover_date']) }}">
                    <div class="form-text">Journals post only on or after this date when set.</div>
                </div>

                @php
                    $accountFields = [
                        'default_revenue_account_code' => 'Default revenue account',
                        'default_refund_account_code' => 'Default refund account',
                        'default_bank_clearing_account_code' => 'Default bank clearing',
                        'default_cash_account_code' => 'Default cash account',
                        'opening_equity_account_code' => 'Opening equity account',
                        'default_misc_expense_account_code' => 'Default misc expense',
                    ];
                @endphp

                @foreach($accountFields as $key => $label)
                    <div class="col-md-6">
                        <label for="{{ $key }}" class="form-label">{{ $label }}</label>
                        <select id="{{ $key }}" name="{{ $key }}" class="form-select @error($key) is-invalid @enderror">
                            <option value="">—</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->code }}" @selected(old($key, $settings[$key]) === $account->code)>
                                    {{ $account->code }} — {{ $account->name }}
                                </option>
                            @endforeach
                        </select>
                        @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Save Preferences</button>
                </div>
            </form>
        </div>
    </div>
@endsection
