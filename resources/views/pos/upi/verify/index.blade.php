@extends('layouts.app')

@section('title', 'Verify UPI payments')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
        <h1 class="h3 mb-1">Verify UPI payments</h1>
        <p class="text-muted mb-0">Check the live bank account, then enter the UTR. A QR is never confirmation.</p>
    </div>
    @include('pos.partials.workspace-nav', ['active' => 'upi-verify'])
    @include('inventory.partials.branch-scope-empty')

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Reference, TR, UTR, customer">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" name="amount" value="{{ $filters['amount'] ?? '' }}" class="form-control" placeholder="Amount">
        </div>
        <div class="col-md-3">
            <select name="receiving_bank_account_id" class="form-select">
                <option value="">All receiving accounts</option>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected((string) ($filters['receiving_bank_account_id'] ?? '') === (string) $account->id)>
                        {{ $account->bank_name }} · {{ $account->last_four }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control" aria-label="From date">
        </div>
        <div class="col-md-2">
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control" aria-label="To date">
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="pending" @selected(($filters['status'] ?? 'pending') === 'pending')>Pending</option>
                <option value="completed" @selected(($filters['status'] ?? '') === 'completed')>Completed</option>
                <option value="abandoned" @selected(($filters['status'] ?? '') === 'abandoned')>Abandoned</option>
                <option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>Cancelled</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary">Search</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Account</th>
                        <th class="text-end">Amount</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($intents as $intent)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $intent->public_ref }}</div>
                                <div class="small text-muted">{{ $intent->tr }}</div>
                            </td>
                            <td>
                                <div>{{ $intent->customer_name }}</div>
                                <div class="small text-muted">{{ $intent->customer_phone }}</div>
                            </td>
                            <td>{{ $intent->receivingAccountLabel() }}</td>
                            <td class="text-end">{{ number_format((float) $intent->amount, 2) }}</td>
                            <td class="small text-muted">{{ $intent->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('pos.upi.payments.show', $intent) }}" class="btn btn-sm btn-primary">Verify</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No UPI payments match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $intents->links() }}</div>
@endsection
