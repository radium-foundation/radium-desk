@extends('layouts.app')

@section('title', $todo->title)

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('todos.index') }}">To-Dos</a></li>
                <li class="breadcrumb-item active" aria-current="page">Details</li>
            </ol>
        </nav>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
            <div>
                <h1 @class(['h3 mb-1', 'text-muted text-decoration-line-through' => $todo->status === \App\Enums\TodoStatus::Cancelled])>
                    {{ $todo->title }}
                </h1>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    @include('todos.partials.status-badge', ['status' => $todo->status])
                    @include('todos.partials.priority-badge', ['priority' => $todo->priority])
                    @if($todo->isOverdue())
                        <span class="badge text-bg-danger">Overdue</span>
                    @endif
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @can('update', $todo)
                    @if($todo->status === \App\Enums\TodoStatus::Open)
                        <a href="{{ route('todos.edit', $todo) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                    @endif
                @endcan
                @can('complete', $todo)
                    @if($todo->status === \App\Enums\TodoStatus::Open)
                        <form method="POST" action="{{ route('todos.complete', $todo) }}">
                            @csrf
                            <button type="submit" class="btn btn-success btn-sm">Complete</button>
                        </form>
                    @endif
                @endcan
                @can('update', $todo)
                    @if($todo->status === \App\Enums\TodoStatus::Completed)
                        <form method="POST" action="{{ route('todos.reopen', $todo) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Reopen</button>
                        </form>
                    @endif
                @endcan
                @can('cancel', $todo)
                    @if($todo->status !== \App\Enums\TodoStatus::Cancelled)
                        <form method="POST" action="{{ route('todos.cancel', $todo) }}"
                              onsubmit="return confirm('Cancel this to-do?');">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning btn-sm">Cancel</button>
                        </form>
                    @endif
                @endcan
                @can('delete', $todo)
                    <form method="POST" action="{{ route('todos.destroy', $todo) }}"
                          onsubmit="return confirm('Delete this to-do permanently?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                    </form>
                @endcan
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9">{{ $todo->description ?: '—' }}</dd>

                        <dt class="col-sm-3">Creator</dt>
                        <dd class="col-sm-9">{{ $todo->creator?->name ?? '—' }}</dd>

                        <dt class="col-sm-3">Assignee</dt>
                        <dd class="col-sm-9">{{ $todo->assignee?->name ?? '—' }}</dd>

                        <dt class="col-sm-3">Due</dt>
                        <dd class="col-sm-9">
                            @if($todo->due_at)
                                {{ display_app_datetime_24($todo->due_at) }}
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-3">Reminder</dt>
                        <dd class="col-sm-9">
                            @if($pendingReminder?->remind_at)
                                {{ display_app_datetime_24($pendingReminder->remind_at) }}
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-3">Completed</dt>
                        <dd class="col-sm-9">
                            {{ $todo->completed_at ? display_app_datetime_24($todo->completed_at) : '—' }}
                        </dd>

                        <dt class="col-sm-3">Created</dt>
                        <dd class="col-sm-9">{{ display_app_datetime_24($todo->created_at) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            @can('assign', $todo)
                @if($todo->status === \App\Enums\TodoStatus::Open && $assignableUsers->isNotEmpty())
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h2 class="h6 mb-0">Assign</h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('todos.assign', $todo) }}">
                                @csrf
                                <label for="assigned_to" class="form-label">Assignee</label>
                                <select id="assigned_to" name="assigned_to"
                                        class="form-select @error('assigned_to') is-invalid @enderror" required>
                                    @foreach($assignableUsers as $user)
                                        <option value="{{ $user->id }}" @selected((int) $todo->assigned_to === (int) $user->id)>
                                            {{ $user->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('assigned_to')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <button type="submit" class="btn btn-primary btn-sm mt-3">Update assignee</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
