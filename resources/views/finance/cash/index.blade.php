@extends('layouts.app')

@section('title', 'Cash Ledger')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Cash Ledger</h1>
        <p class="text-muted mb-0">Movements from the general ledger for linked cash accounts.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'cash'])

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <form method="GET" action="{{ route('finance.cash.index') }}">
                <label for="cash_account_id" class="form-label">Cash account</label>
                <select id="cash_account_id" name="cash_account_id" class="form-select" onchange="this.form.submit()">
                    @foreach($accounts as $account)
                        <option value="{{ $account['id'] }}" @selected($selectedAccount?->id === $account['id'])>
                            {{ $account['label'] }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Balance</p>
                    <p class="h4 mb-0">{{ number_format($balance, 2) }}</p>
                    @if($selectedAccount?->glAccount)
                        <p class="text-muted small mb-0 mt-1">GL {{ $selectedAccount->glAccount->code }} · {{ $selectedAccount->glAccount->name }}</p>
                    @else
                        <p class="text-warning small mb-0 mt-1">No GL link configured</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Journal</th>
                        <th>Memo</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lines as $line)
                        <tr>
                            <td>{{ display_app_date($line->journal->entry_date) }}</td>
                            <td>
                                <a href="{{ route('finance.settings.journals.show', $line->journal) }}">
                                    {{ $line->journal->journal_no }}
                                </a>
                            </td>
                            <td>{{ $line->description ?: $line->journal->memo }}</td>
                            <td class="text-end">{{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}</td>
                            <td class="text-end">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted text-center py-4">No ledger movements yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($lines->hasPages())
            <div class="card-footer bg-white">{{ $lines->links() }}</div>
        @endif
    </div>
@endsection
