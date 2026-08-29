@extends('layouts.app')

@section('title', 'New To-Do')

@section('content')
    <div class="todo-page">
        @include('todos.partials.panel-form', [
            'assignableUsers' => $assignableUsers,
            'categories' => $categories,
        ])
    </div>
@endsection
