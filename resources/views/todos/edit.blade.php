@extends('layouts.app')

@section('title', 'Edit To-Do')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('todos.index') }}">To-Dos</a></li>
                <li class="breadcrumb-item"><a href="{{ route('todos.show', $todo) }}">{{ $todo->title }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Edit to-do</h1>
        <p class="text-muted mb-0">Update details, due date, or reminder.</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @include('todos.partials.form', [
                'todo' => $todo,
                'assignableUsers' => $assignableUsers,
                'pendingReminder' => $pendingReminder,
            ])
        </div>
    </div>
@endsection
