@extends('layouts.app')

@section('title', $todo->title)

@section('content')
    <div class="todo-page">
        @include('todos.partials.panel-detail', [
            'todo' => $todo,
            'assignableUsers' => $assignableUsers,
            'pendingReminder' => $pendingReminder,
        ])
    </div>
@endsection
