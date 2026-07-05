@extends('layouts.app')

@section('title', 'Profile')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Profile</h1>
        <p class="text-muted mb-0">Update your account information and password.</p>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Profile Information</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf
                        @method('patch')

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $user->name) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $user->email) }}" required>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Update Password</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        @method('put')

                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control @error('current_password', 'updatePassword') is-invalid @enderror" required>
                            @error('current_password', 'updatePassword')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">New password</label>
                            <input type="password" id="password" name="password"
                                   class="form-control @error('password', 'updatePassword') is-invalid @enderror" required>
                            @error('password', 'updatePassword')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm new password</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required>
                        </div>

                        <button type="submit" class="btn btn-primary">Update password</button>
                    </form>
                </div>
            </div>
        </div>

        @if($showsTeamAvailability ?? false)
            <div class="col-lg-6">
                @include('profile.partials.team-availability', ['availability' => $availability ?? []])
            </div>
            @can('create', \App\Models\LeaveRequest::class)
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h2 class="h6 mb-0">Leave Requests</h2>
                        </div>
                        <div class="card-body d-flex flex-column">
                            <p class="text-muted small">
                                Submit official leave for operations approval. Approved leave blocks smart assignment.
                            </p>
                            <div class="mt-auto d-flex flex-wrap gap-2">
                                <a href="{{ route('leave-requests.create') }}" class="btn btn-primary">Request Leave</a>
                                <a href="{{ route('leave-requests.index') }}" class="btn btn-outline-secondary">View Requests</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endcan
        @endif

        <div class="col-lg-6">
            @include('profile.partials.telegram-notifications', ['user' => $user])
        </div>
    </div>
@endsection
