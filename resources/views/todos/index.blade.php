@extends('layouts.app')

@section('title', 'To-Dos')

@section('content')
    <div class="todo-page">
        <div class="todo-page__intro">
            <h1 class="h5 mb-1">To-Dos</h1>
            <p class="text-muted small mb-0">Prefer the top-bar or sidebar To-Dos action — it opens beside your current page.</p>
        </div>
        @include('todos.partials.panel-list', [
            'todos' => $todos,
            'filters' => $filters,
        ])
    </div>
@endsection
