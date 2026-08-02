@extends('layouts.app')

@section('title', 'Journal Audit')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <h1 class="h3 mb-1">Journal Audit</h1>
        <p class="text-muted mb-0">Immutable double-entry journals posted by the ledger engine.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'journals'])

    <form method="GET" action="{{ route('finance.settings.journals') }}" class="row g-3 mb-4">
        <div class="col-md-4">
            <label for="source_type" class="form-label">Source</label>
            <select id="source_type" name="source_type" class="form-select" onchange="this.form.submit()">
                <option value="">All sources</option>
                @foreach($sourceTypes as $type)
                    <option value="{{ $type->value }}" @selected($selectedSourceType === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Journal</th>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Memo</th>
                        <th>Posted by</th>
                        <th class="text-end">Debits</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($journals as $journal)
                        <tr>
                            <td>
                                <a href="{{ route('finance.settings.journals.show', $journal) }}">{{ $journal->journal_no }}</a>
                            </td>
                            <td>{{ display_app_date($journal->entry_date) }}</td>
                            <td>{{ $journal->source_type->label() }}</td>
                            <td>{{ $journal->memo }}</td>
                            <td>{{ $journal->poster?->name ?? 'System' }}</td>
                            <td class="text-end">{{ $journal->totalDebits() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted text-center py-4">No journals posted yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($journals->hasPages())
            <div class="card-footer bg-white">{{ $journals->links() }}</div>
        @endif
    </div>
@endsection
