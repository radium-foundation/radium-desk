@extends('layouts.app')

@section('title', 'Edit To-Do')

@section('content')
    <div class="todo-page">
        @include('todos.partials.panel-form', [
            'todo' => $todo,
            'assignableUsers' => $assignableUsers,
            'categories' => $categories,
            'pendingReminder' => $pendingReminder,
        ])
    </div>
@endsection
