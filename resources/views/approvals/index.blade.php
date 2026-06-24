@extends('layouts.app')

@section('title', 'Approvals')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Approvals</h1>
            <p class="text-muted mb-0">Manage approval numbers and linked incidents.</p>
        </div>
        @can('create', App\Models\ApprovalNumber::class)
            <a href="{{ route('approvals.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Create Approval
            </a>
        @endcan
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Search</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('approvals.index') }}" class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <label for="filter_approval_number" class="form-label">Approval Number</label>
                    <input type="text" name="approval_number" id="filter_approval_number" class="form-control"
                           value="{{ $filters['approval_number'] ?? '' }}" placeholder="Search approval number">
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                    <a href="{{ route('approvals.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($approvals->isEmpty())
                <div class="p-4 text-center text-muted">
                    No approval numbers found.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Approval Number</th>
                                <th class="text-center">Linked Incidents</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($approvals as $approval)
                                <tr>
                                    <td class="fw-semibold">
                                        <a href="{{ route('approvals.show', $approval) }}" class="text-decoration-none">
                                            {{ $approval->approval_number }}
                                        </a>
                                        @if($approval->description)
                                            <div class="small text-muted">{{ Str::limit($approval->description, 60) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @include('approvals.partials.incident-counter', [
                                            'count' => $approval->incidents_count,
                                        ])
                                    </td>
                                    <td>{{ $approval->creator?->name ?? '—' }}</td>
                                    <td>{{ $approval->created_at?->format('d M Y, H:i') ?: '—' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('approvals.show', $approval) }}" class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($approvals->hasPages())
            <div class="card-footer bg-white">
                {{ $approvals->links() }}
            </div>
        @endif
    </div>
@endsection
