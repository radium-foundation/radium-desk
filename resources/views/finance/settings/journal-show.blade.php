@extends('layouts.app')

@section('title', $journal->journal_no)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Settings</p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('finance.settings.journals') }}">Journal Audit</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $journal->journal_no }}</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">{{ $journal->journal_no }}</h1>
        <p class="text-muted mb-0">{{ display_app_date($journal->entry_date) }} · {{ $journal->source_type->label() }}</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'settings'])
    @include('finance.partials.settings-nav', ['active' => 'journals'])

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Memo</dt>
                <dd class="col-sm-9">{{ $journal->memo }}</dd>
                <dt class="col-sm-3">Idempotency key</dt>
                <dd class="col-sm-9"><code>{{ $journal->idempotency_key }}</code></dd>
                <dt class="col-sm-3">Posted by</dt>
                <dd class="col-sm-9">{{ $journal->poster?->name ?? 'System' }} · {{ display_app_datetime_24($journal->posted_at) }}</dd>
                <dt class="col-sm-3">Source</dt>
                <dd class="col-sm-9">{{ $journal->source_type->label() }} @if($journal->source_id)#{{ $journal->source_id }}@endif</dd>
            </dl>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($journal->lines as $line)
                        <tr>
                            <td>{{ $line->line_no }}</td>
                            <td>{{ $line->account?->code }} — {{ $line->account?->name }}</td>
                            <td>{{ $line->description ?? '—' }}</td>
                            <td class="text-end">{{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}</td>
                            <td class="text-end">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="3" class="text-end">Totals</td>
                        <td class="text-end">{{ $journal->totalDebits() }}</td>
                        <td class="text-end">{{ $journal->totalCredits() }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endsection
