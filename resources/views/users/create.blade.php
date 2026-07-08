@extends('layouts.app')

@section('title', 'Create User')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
                <li class="breadcrumb-item active" aria-current="page">Create</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Create User</h1>
        <p class="text-muted mb-0">Add a new user account to the service desk.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('users.store') }}">
                @csrf

                @include('users.partials.form', [
                    'user' => $user,
                    'roleOptions' => $roleOptions,
                    'showPassword' => true,
                ])

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Create User</button>
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
