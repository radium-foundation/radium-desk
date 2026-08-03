@extends('layouts.app')

@section('title', 'Cash Book')

@section('content')
    @php
        $period = $filters['period'] ?? 'today';
        $typeFilter = $filters['type'] ?? '';
        $query = $filters['q'] ?? '';
        $colspan = $canManage ? 8 : 7;

        $periodLink = function (string $value) use ($filters) {
            return route('cash-book.index', array_filter([
                'period' => $value,
                'type' => $filters['type'] ?? null,
                'q' => $filters['q'] ?? null,
                'date_from' => $value === 'custom' ? ($filters['date_from'] ?? null) : null,
                'date_to' => $value === 'custom' ? ($filters['date_to'] ?? null) : null,
            ], fn ($v) => $v !== null && $v !== ''));
        };

        $typeLink = function (string $value) use ($filters) {
            return route('cash-book.index', array_filter([
                'period' => $filters['period'] ?? 'today',
                'type' => $value,
                'q' => $filters['q'] ?? null,
                'date_from' => ($filters['period'] ?? '') === 'custom' ? ($filters['date_from'] ?? null) : null,
                'date_to' => ($filters['period'] ?? '') === 'custom' ? ($filters['date_to'] ?? null) : null,
            ], fn ($v) => $v !== null && $v !== ''));
        };
    @endphp

    <div class="mb-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Operations</p>
            <h1 class="h3 mb-1">Cash Book</h1>
            <p class="text-muted mb-0">Record cash income and expenses in seconds.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('cash-book.create') }}" class="btn btn-primary">
                + Add Entry
            </a>
        @endif
    </div>

    @if (session('status') === 'cash-book-entry-created')
        <div class="alert alert-success">Entry {{ session('status_entry_no') }} saved.</div>
    @elseif (session('status') === 'cash-book-entry-updated')
        <div class="alert alert-success">Entry updated.</div>
    @elseif (session('status') === 'cash-book-entry-deleted')
        <div class="alert alert-success">Entry deleted.</div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Today's Income</p>
                    <p class="h3 mb-0 text-success">₹{{ number_format($summary['todays_income'], 2) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Today's Expense</p>
                    <p class="h3 mb-0 text-danger">₹{{ number_format($summary['todays_expense'], 2) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Available Cash</p>
                    <p class="h3 mb-0">₹{{ number_format($summary['available_cash'], 2) }}</p>
                    <p class="text-muted small mb-0 mt-2">Income − Expense − Handed Over + Received Back</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('cash-book.index') }}" id="cash-book-filter-form" class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label for="cash-book-search" class="form-label">Search</label>
                    <input
                        type="search"
                        name="q"
                        id="cash-book-search"
                        value="{{ $query }}"
                        class="form-control"
                        placeholder="Remark, person, category, amount, reference"
                        autocomplete="off"
                    >
                </div>
                <div class="col-lg-8">
                    <label class="form-label d-block">Filters</label>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        @foreach ([
                            'today' => 'Today',
                            'yesterday' => 'Yesterday',
                            'this_week' => 'This Week',
                            'this_month' => 'This Month',
                            'custom' => 'Custom',
                        ] as $value => $label)
                            <a
                                href="{{ $periodLink($value) }}"
                                class="btn btn-sm {{ $period === $value ? 'btn-dark' : 'btn-outline-secondary' }}"
                            >{{ $label }}</a>
                        @endforeach

                        <span class="text-muted mx-1">|</span>

                        <a
                            href="{{ $typeLink('') }}"
                            class="btn btn-sm {{ $typeFilter === '' ? 'btn-dark' : 'btn-outline-secondary' }}"
                        >All</a>
                        <a
                            href="{{ $typeLink('income') }}"
                            class="btn btn-sm {{ $typeFilter === 'income' ? 'btn-success' : 'btn-outline-success' }}"
                        >Income</a>
                        <a
                            href="{{ $typeLink('expense') }}"
                            class="btn btn-sm {{ $typeFilter === 'expense' ? 'btn-danger' : 'btn-outline-danger' }}"
                        >Expense</a>
                    </div>
                </div>

                <input type="hidden" name="period" value="{{ $period }}">
                <input type="hidden" name="type" value="{{ $typeFilter }}">

                @if ($period === 'custom')
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">From</label>
                        <input type="date" name="date_from" id="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">To</label>
                        <input type="date" name="date_to" id="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary">Apply dates</button>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Ledger</h2>
            <span class="text-muted small">Newest first</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Income / Expense</th>
                            <th class="text-end">Amount</th>
                            <th>Income Source / Expense Category</th>
                            <th>Received From / Paid To</th>
                            <th>Remark</th>
                            <th>Created By</th>
                            @if ($canManage)
                                <th class="text-end">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">
                                    <div>{{ $entry->created_at?->timezone(config('app.timezone'))->format('d M Y') }}</div>
                                    <div class="text-muted small">{{ $entry->created_at?->timezone(config('app.timezone'))->format('h:i A') }}</div>
                                </td>
                                <td>
                                    @if ($entry->isIncome())
                                        <span class="badge text-bg-success">Income</span>
                                    @else
                                        <span class="badge text-bg-danger">Expense</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap fw-semibold">₹{{ number_format((float) $entry->amount, 2) }}</td>
                                <td>{{ $entry->categoryLabel() }}</td>
                                <td>{{ $entry->person ?: '—' }}</td>
                                <td>{{ $entry->remark }}</td>
                                <td>{{ $entry->creator?->name ?? '—' }}</td>
                                @if ($canManage)
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('cash-book.edit', $entry) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        <form method="POST" action="{{ route('cash-book.destroy', $entry) }}" class="d-inline" onsubmit="return confirm('Delete this entry? The ledger journal will be reversed.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $colspan }}" class="text-center text-muted py-5">
                                    No entries match these filters.
                                    @if ($canCreate)
                                        <a href="{{ route('cash-book.create') }}">Add an entry</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($entries->hasPages())
            <div class="card-footer bg-white border-0">
                {{ $entries->links() }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('cash-book-filter-form');
    const search = document.getElementById('cash-book-search');
    if (!form || !search) return;

    let timer = null;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => form.requestSubmit(), 300);
    });
})();
</script>
@endpush
