@extends('layouts.app')

@section('title', 'Return cases')

@section('content')
    <div class="container py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0">Return / exchange cases</h1>
            <div class="d-flex flex-wrap gap-2">
                @can('viewCentralQueue', \App\Models\ReturnExchangeCase::class)
                    <a href="{{ route('return-cases.central-queue') }}" class="btn btn-outline-secondary btn-sm">Central queue</a>
                @endcan
                @can('create', \App\Models\ReturnExchangeCase::class)
                    <a href="{{ route('return-cases.create') }}" class="btn btn-primary btn-sm">New case</a>
                @endcan
            </div>
        </div>

        <p class="text-muted small">
            Internal intake only. Recording a case does not change stock, invoices, payments, or refunds.
        </p>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Case</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Branch</th>
                        <th>Requested</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cases as $case)
                        <tr>
                            <td>{{ $case->case_no }}</td>
                            <td>{{ $case->source_kind->label() }}</td>
                            <td>{{ $case->status->label() }}</td>
                            <td>{{ $case->branch?->name ?? '—' }}</td>
                            <td>{{ $case->created_at?->format('d M Y H:i') }}</td>
                            <td>
                                <a href="{{ route('return-cases.show', $case) }}">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No cases yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $cases->links() }}
    </div>
@endsection
