@extends('layouts.app')

@section('title', 'Review Entry')

@section('content')
    @php
        $payload = $payload ?? [];
        $isHistorical = ($mode ?? 'create') === 'historical';
        $type = $payload['type'] ?? 'income';
        $isIncome = $type === 'income';
        $categoryValue = $payload['category'] ?? null;
        $categoryLabel = $categoryValue ?? '—';
        if ($isIncome) {
            foreach ($incomeSources as $source) {
                if ($source->value === $categoryValue) {
                    $categoryLabel = $source->label();
                    break;
                }
            }
        } else {
            foreach ($expenseCategories as $expenseCategory) {
                if ($expenseCategory->value === $categoryValue) {
                    $categoryLabel = $expenseCategory->label();
                    break;
                }
            }
        }
        $action = $isHistorical ? route('cash-book.historical.store') : route('cash-book.store');
        $cancel = $isHistorical ? route('cash-book.historical.create') : route('cash-book.create');
    @endphp

    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Operations</p>
        <h1 class="h3 mb-1">Review Entry</h1>
        <p class="text-muted mb-0">Confirm before this entry is locked and posted to the ledger.</p>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Type</dt>
                <dd class="col-sm-8">{{ $isIncome ? 'Income' : 'Expense' }}</dd>

                <dt class="col-sm-4">Amount</dt>
                <dd class="col-sm-8">₹{{ number_format((float) ($payload['amount'] ?? 0), 2) }}</dd>

                <dt class="col-sm-4">{{ $isIncome ? 'Income Source' : 'Expense Category' }}</dt>
                <dd class="col-sm-8">{{ $categoryLabel }}</dd>

                <dt class="col-sm-4">{{ $isIncome ? 'Received From' : 'Paid To' }}</dt>
                <dd class="col-sm-8">{{ filled($payload['person'] ?? null) ? $payload['person'] : '—' }}</dd>

                <dt class="col-sm-4">Remark</dt>
                <dd class="col-sm-8">{{ $payload['remark'] ?? '—' }}</dd>

                <dt class="col-sm-4">Date</dt>
                <dd class="col-sm-8">{{ $payload['entry_date'] ?? '—' }}</dd>

                @if ($isHistorical)
                    <dt class="col-sm-4">Historical Reason</dt>
                    <dd class="col-sm-8">{{ $payload['historical_reason'] ?? '—' }}</dd>
                @elseif (filled($payload['backdate_reason'] ?? null))
                    <dt class="col-sm-4">Back-date Reason</dt>
                    <dd class="col-sm-8">{{ $payload['backdate_reason'] }}</dd>
                @endif
            </dl>
        </div>
    </div>

    <form method="POST" action="{{ $action }}">
        @csrf
        @foreach ($payload as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <input type="hidden" name="confirmed" value="1">
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ $cancel }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Confirm Entry</button>
        </div>
    </form>
@endsection
