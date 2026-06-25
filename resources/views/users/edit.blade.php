@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $user->firstName() }}</li>
            </ol>
        </nav>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h1 class="h3 mb-1">Edit User</h1>
                <p class="text-muted mb-0">Update account details and access.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @can('resetPassword', $user)
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                        <i class="bi bi-key me-1"></i> Reset Password
                    </button>
                @endcan
                @can('delete', $user)
                    <form method="POST" action="{{ route('users.destroy', $user) }}"
                          onsubmit="return confirm('Delete this user? This action can be restored only from the database.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="bi bi-trash me-1"></i> Delete
                        </button>
                    </form>
                @endcan
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route('users.update', $user) }}">
                        @csrf
                        @method('PUT')

                        @include('users.partials.form', [
                            'user' => $user,
                            'roles' => $roles,
                            'currentRole' => $currentRole,
                            'showStatus' => true,
                        ])

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Account Info</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Created</dt>
                        <dd class="col-sm-7">{{ display_app_datetime_24($user->created_at) }}</dd>
                        <dt class="col-sm-5">Assigned Cases</dt>
                        <dd class="col-sm-7">{{ $user->assignedIncidents()->count() }}</dd>
                    </dl>
                </div>
            </div>

            @can('updateStatus', $user)
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Status</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">
                            Inactive users cannot log in but remain in audit logs and on assigned service cases.
                        </p>
                        <form method="POST" action="{{ route('users.status.update', $user) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                            <button type="submit" class="btn btn-{{ $user->is_active ? 'outline-warning' : 'outline-success' }} w-100">
                                @if($user->is_active)
                                    <i class="bi bi-person-x me-1"></i> Deactivate User
                                @else
                                    <i class="bi bi-person-check me-1"></i> Activate User
                                @endif
                            </button>
                        </form>
                    </div>
                </div>
            @endcan
        </div>
    </div>

    @can('resetPassword', $user)
        <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('users.password-reset.update', $user) }}">
                        @csrf
                        @method('PATCH')
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="resetPasswordModalLabel">Reset Password</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="reset_password" class="form-label">New Password</label>
                                <input type="password" name="password" id="reset_password"
                                       class="form-control @error('password') is-invalid @enderror" required>
                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-0">
                                <label for="reset_password_confirmation" class="form-label">Confirm Password</label>
                                <input type="password" name="password_confirmation" id="reset_password_confirmation"
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Reset Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
@endsection

@push('scripts')
    @if($errors->has('password'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modal = document.getElementById('resetPasswordModal');
                if (modal) {
                    bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            });
        </script>
    @endif
@endpush
