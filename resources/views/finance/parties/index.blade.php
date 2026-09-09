@extends('layouts.app')

@section('title', 'Parties')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h1 class="h3 mb-1">Parties</h1>
                <p class="text-muted mb-0">Customer and vendor legal entities for future billing, PO, and PI. POS till customers stay separate.</p>
            </div>
            @if($canManage)
                <a href="{{ route('finance.parties.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> New Party
                </a>
            @endif
        </div>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'parties'])

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('finance.parties.index') }}" class="row g-3">
                <div class="col-md-4">
                    <label for="q" class="form-label">Search</label>
                    <input type="text" id="q" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="Code, name, phone, email, GSTIN">
                </div>
                <div class="col-md-3">
                    <label for="role" class="form-label">Role</label>
                    <select id="role" name="role" class="form-select">
                        <option value="">All</option>
                        <option value="customer" @selected(($filters['role'] ?? '') === 'customer')>Customer</option>
                        <option value="vendor" @selected(($filters['role'] ?? '') === 'vendor')>Vendor</option>
                        <option value="both" @selected(($filters['role'] ?? '') === 'both')>Both</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">All</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
            <div class="mt-2">
                <a href="{{ route('finance.parties.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Legal name</th>
                        <th>Roles</th>
                        <th>Phone</th>
                        <th>GSTIN</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($parties as $party)
                        <tr>
                            <td>
                                <a href="{{ route('finance.parties.show', $party) }}">{{ $party->code }}</a>
                            </td>
                            <td>
                                <div>{{ $party->legal_name }}</div>
                                @if($party->trade_name)
                                    <div class="text-muted small">{{ $party->trade_name }}</div>
                                @endif
                            </td>
                            <td>
                                @foreach($party->roles as $role)
                                    <span class="badge text-bg-light border">{{ $role->role->label() }}</span>
                                @endforeach
                            </td>
                            <td>{{ $party->phone ?: '—' }}</td>
                            <td>{{ $party->primaryGstRegistration?->gstin ?: '—' }}</td>
                            <td>
                                @if($party->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted py-4 text-center">No parties yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($parties->hasPages())
            <div class="card-footer bg-white">
                {{ $parties->links() }}
            </div>
        @endif
    </div>
@endsection
