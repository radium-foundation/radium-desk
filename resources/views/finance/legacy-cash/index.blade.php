@extends('layouts.app')

@section('title', 'Legacy Cash — RadiumBox Admin')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Legacy Cash — RadiumBox Admin</h1>
        <p class="text-muted mb-0">
            Read-only historical cash from Old Admin <code>expenses</code>.
            These rows are not Cash Book entries and do not post individually into the live GL.
        </p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'legacy_cash'])

    <div class="alert alert-warning" role="status">
        <strong>Separated from operational Finance.</strong>
        Investigate history here. New cash after
        {{ \App\Support\Finance\LegacyCashContract::CUTOFF_AT }} IST belongs in Desk Cash Book / Finance.
        The approved operational opening is a single journal
        <em>{{ \App\Support\Finance\LegacyCashContract::OPENING_MEMO }}</em>
        for ₹{{ number_format((float) \App\Support\Finance\LegacyCashContract::OPENING_AMOUNT, 2) }},
        not these {{ number_format(\App\Support\Finance\LegacyCashContract::EXPECTED_ROWS) }} rows.
    </div>

    @if($opening)
        <div class="alert alert-info" role="status">
            Operational opening journal
            <a href="{{ route('finance.settings.journals.show', $opening) }}">{{ $opening->journal_no }}</a>
            dated {{ display_app_date($opening->entry_date) }}
            — {{ $opening->memo }}
            (₹{{ number_format((float) \App\Support\Finance\LegacyCashContract::OPENING_AMOUNT, 2) }}).
        </div>
    @else
        <div class="alert alert-secondary" role="status">
            Operational opening journal has not been posted yet. Legacy history below is still read-only and off the live GL.
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Rows</p>
                    <p class="h5 mb-0">{{ number_format($totals['rows']) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Credits</p>
                    <p class="h5 mb-0">{{ number_format($totals['credits']) }}</p>
                    <p class="small text-muted mb-0">₹{{ number_format($totals['credit_total'], 2) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Debits</p>
                    <p class="h5 mb-0">{{ number_format($totals['debits']) }}</p>
                    <p class="small text-muted mb-0">₹{{ number_format($totals['debit_total'], 2) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Net</p>
                    <p class="h5 mb-0">₹{{ number_format($totals['net'], 2) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Unmapped</p>
                    <p class="h5 mb-0">{{ number_format($totals['unmapped']) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1">Needs review</p>
                    <p class="h5 mb-0">{{ number_format($totals['review']) }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('finance.legacy-cash.index') }}" class="row g-3">
                <div class="col-md-3">
                    <label for="q" class="form-label">Search</label>
                    <input
                        type="text"
                        id="q"
                        name="q"
                        class="form-control"
                        value="{{ $filters['q'] }}"
                        placeholder="Legacy ID, description, Admin user"
                    >
                </div>
                <div class="col-md-2">
                    <label for="type" class="form-label">Type</label>
                    <select id="type" name="type" class="form-select">
                        <option value="">All</option>
                        <option value="credit" @selected($filters['type'] === 'credit')>Credit</option>
                        <option value="debit" @selected($filters['type'] === 'debit')>Debit</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="review_status" class="form-label">Review</label>
                    <select id="review_status" name="review_status" class="form-select">
                        <option value="">All</option>
                        <option value="ok" @selected($filters['review_status'] === 'ok')>OK</option>
                        <option value="needs_review" @selected($filters['review_status'] === 'needs_review')>Needs review</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="mapped" class="form-label">Desk user</label>
                    <select id="mapped" name="mapped" class="form-select">
                        <option value="">All</option>
                        <option value="yes" @selected($filters['mapped'] === 'yes')>Mapped</option>
                        <option value="no" @selected($filters['mapped'] === 'no')>Unmapped</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="amount_type" class="form-label">Category</label>
                    <select id="amount_type" name="amount_type" class="form-select">
                        <option value="">All</option>
                        @foreach($categories as $category)
                            <option value="{{ $category }}" @selected($filters['amount_type'] === $category)>
                                {{ $category }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('finance.legacy-cash.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Legacy ID</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Description</th>
                        <th>Old Admin User</th>
                        <th>Desk User</th>
                        <th>Category</th>
                        <th>Status / Review</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr>
                            <td>{{ $entry->legacy_transaction_id }}</td>
                            <td>{{ display_app_datetime($entry->original_created_at) }}</td>
                            <td>
                                <span @class(['badge', 'text-bg-success' => $entry->isCredit(), 'text-bg-danger' => $entry->isDebit()])>
                                    {{ ucfirst($entry->entry_type) }}
                                </span>
                            </td>
                            <td class="text-end">₹{{ number_format((float) $entry->amount, 2) }}</td>
                            <td>{{ $entry->description ?: '—' }}</td>
                            <td>
                                {{ $entry->legacy_admin_name ?: '—' }}
                                <div class="small text-muted">Admin {{ $entry->legacy_created_by }}</div>
                            </td>
                            <td>
                                @if($entry->deskUser)
                                    {{ $entry->deskUser->name }}
                                    <div class="small text-muted">Desk {{ $entry->deskUser->id }}</div>
                                @else
                                    <span class="text-muted">Unmapped</span>
                                @endif
                            </td>
                            <td>{{ $entry->amount_type ?: '—' }}</td>
                            <td>
                                @if($entry->needsReview())
                                    <span class="badge text-bg-warning">Needs review</span>
                                    <div class="small text-muted">{{ $entry->review_reason }}</div>
                                @else
                                    <span class="badge text-bg-light">Imported</span>
                                @endif
                            </td>
                            <td>
                                <div>{{ $entry->legacy_source }}</div>
                                <div class="small text-muted font-monospace">{{ $entry->idempotency_key }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-muted text-center py-4">No Legacy Cash rows imported yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($entries->hasPages())
            <div class="card-footer bg-white">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
