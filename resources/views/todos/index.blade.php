@extends('layouts.app')

@section('title', 'To-Dos')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">To-Dos</h1>
            <p class="text-muted mb-0">Personal and assigned tasks with optional due dates and reminders.</p>
        </div>
        @can('create', \App\Models\Todo::class)
            <a href="{{ route('todos.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> New to-do
            </a>
        @endcan
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('todos.index') }}" class="row g-3">
                <div class="col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="" @selected(($filters['status'] ?? '') === '')>Open (default)</option>
                        @foreach(\App\Enums\TodoStatus::cases() as $statusOption)
                            <option value="{{ $statusOption->value }}" @selected(($filters['status'] ?? '') === $statusOption->value)>
                                {{ $statusOption->label() }}
                            </option>
                        @endforeach
                        <option value="all" @selected(($filters['status'] ?? '') === 'all')>All statuses</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="scope" class="form-label">Scope</label>
                    <select id="scope" name="scope" class="form-select">
                        <option value="" @selected(($filters['scope'] ?? '') === '')>Mine (created or assigned)</option>
                        <option value="assigned" @selected(($filters['scope'] ?? '') === 'assigned')>Assigned to me</option>
                        <option value="created" @selected(($filters['scope'] ?? '') === 'created')>Created by me</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('todos.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Assignee</th>
                        <th>Priority</th>
                        <th>Due</th>
                        <th>Reminder</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($todos as $todo)
                        @php
                            $pendingReminder = $todo->pendingReminder();
                            $isSubdued = in_array($todo->status, [\App\Enums\TodoStatus::Completed, \App\Enums\TodoStatus::Cancelled], true);
                        @endphp
                        <tr @class(['opacity-75' => $isSubdued, 'table-danger' => $todo->isOverdue()])>
                            <td>
                                <a href="{{ route('todos.show', $todo) }}" class="text-decoration-none fw-semibold">
                                    {{ $todo->title }}
                                </a>
                                @if($todo->isOverdue())
                                    <span class="badge text-bg-danger ms-1">Overdue</span>
                                @endif
                            </td>
                            <td>{{ $todo->assignee?->name ?? '—' }}</td>
                            <td>@include('todos.partials.priority-badge', ['priority' => $todo->priority])</td>
                            <td>
                                @if($todo->due_at)
                                    {{ display_app_datetime_24($todo->due_at) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($pendingReminder?->remind_at)
                                    {{ display_app_datetime_24($pendingReminder->remind_at) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>@include('todos.partials.status-badge', ['status' => $todo->status])</td>
                            <td class="text-end">
                                <a href="{{ route('todos.show', $todo) }}" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-muted text-center py-4">No to-dos found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($todos->hasPages())
            <div class="card-footer bg-white">
                {{ $todos->links() }}
            </div>
        @endif
    </div>
@endsection
