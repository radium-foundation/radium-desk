@extends('layouts.app')

@section('title', 'Opening Balances')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <h1 class="h3 mb-1">Opening Balances</h1>
        <p class="text-muted mb-0">Post Day-0 cash opening balances into the ledger.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'opening_balances'])

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Post Cash Opening</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('finance.settings.opening-balances.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="cash_account_id" class="form-label">Cash account</label>
                            <select id="cash_account_id" name="cash_account_id" class="form-select @error('cash_account_id') is-invalid @enderror" required>
                                @foreach($cashAccounts as $row)
                                    <option value="{{ $row['account']->id }}" @selected((string) old('cash_account_id') === (string) $row['account']->id)>
                                        {{ $row['account']->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('cash_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="amount" class="form-label">Amount</label>
                            <input type="number" step="0.01" min="0.01" id="amount" name="amount" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required>
                            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="entry_date" class="form-label">Entry date</label>
                            <input type="date" id="entry_date" name="entry_date" class="form-control @error('entry_date') is-invalid @enderror" value="{{ old('entry_date', now()->toDateString()) }}" required>
                            @error('entry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Post Opening Balance</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Cash Account</th>
                                <th>GL</th>
                                <th class="text-end">Current Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($cashAccounts as $row)
                                <tr>
                                    <td>{{ $row['account']->name }}</td>
                                    <td>
                                        @if($row['account']->glAccount)
                                            {{ $row['account']->glAccount->code }} — {{ $row['account']->glAccount->name }}
                                        @else
                                            <span class="text-warning">Unlinked</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format($row['balance'], 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-muted text-center py-4">No cash accounts.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
