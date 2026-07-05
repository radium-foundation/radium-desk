@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Users</h1>
            <p class="text-muted mb-0">Manage team members, admins, and account access.</p>
        </div>
        @can('create', App\Models\User::class)
            <a href="{{ route('users.create') }}" class="btn btn-primary">
                <i class="bi bi-person-plus me-1"></i> Create User
            </a>
        @endcan
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Filters</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('users.index') }}" class="row g-3">
                <div class="col-md-4">
                    <label for="filter_q" class="form-label">Search</label>
                    <input type="search" name="q" id="filter_q" class="form-control"
                           value="{{ $filters['q'] ?? '' }}"
                           placeholder="Search by name or email">
                </div>
                <div class="col-md-4">
                    <label for="filter_role" class="form-label">Role</label>
                    <select name="role" id="filter_role" class="form-select">
                        <option value="">All roles</option>
                        @foreach($roles as $role)
                            <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>
                                {{ ucfirst($role) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="filter_status" class="form-label">Status</label>
                    <select name="status" id="filter_status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i> Apply Filters
                    </button>
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($users->isEmpty())
                <div class="p-4 text-center text-muted">
                    No users found.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Avatar</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Assigned Service Cases</th>
                                <th>Created Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $user)
                                <tr>
                                    <td>
                                        <span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center"
                                              style="width: 2rem; height: 2rem; font-size: 0.75rem;">
                                            {{ $user->initials() }}
                                        </span>
                                    </td>
                                    <td>{{ $user->firstName() }}</td>
                                    <td>{{ $user->lastName() ?: '—' }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td>
                                        @foreach($user->roles as $role)
                                            <span class="badge text-bg-secondary">{{ app(\App\Services\Operations\OperationsRoleService::class)->displayLabel($role->name) }}</span>
                                        @endforeach
                                    </td>
                                    <td>
                                        @if($user->is_active)
                                            <span class="badge text-bg-success">Active</span>
                                        @else
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>{{ $user->assigned_incidents_count }}</td>
                                    <td class="text-nowrap">{{ display_app_date($user->created_at) }}</td>
                                    <td class="text-end">
                                        @can('update', $user)
                                            <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($users->hasPages())
            <div class="card-footer bg-white">
                {{ $users->links() }}
            </div>
        @endif
    </div>
@endsection
