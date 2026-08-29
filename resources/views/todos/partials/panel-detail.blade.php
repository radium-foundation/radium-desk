@php
    /** @var \App\Models\Todo $todo */
    /** @var \Illuminate\Support\Collection<int, \App\Models\User> $assignableUsers */
    /** @var \App\Models\Reminder|null $pendingReminder */
@endphp

<div class="todo-panel" data-todo-panel="detail">
    <div class="todo-panel__detail-nav">
        <a href="{{ route('todos.index') }}" class="btn btn-sm btn-link px-0" data-todo-panel-nav>
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> All to-dos
        </a>
    </div>

    <div class="todo-panel__detail-header">
        <h2 @class([
            'todo-panel__detail-title',
            'text-muted text-decoration-line-through' => $todo->status === \App\Enums\TodoStatus::Cancelled,
        ])>
            {{ $todo->title }}
        </h2>
        <div class="todo-panel__detail-badges">
            @include('todos.partials.status-badge', ['status' => $todo->status])
            @include('todos.partials.priority-badge', ['priority' => $todo->priority])
            @if($todo->isOverdue())
                <span class="badge text-bg-danger">Overdue</span>
            @endif
        </div>
    </div>

    <div class="todo-panel__actions">
        @can('update', $todo)
            @if($todo->status === \App\Enums\TodoStatus::Open)
                <a href="{{ route('todos.edit', $todo) }}" class="btn btn-outline-primary btn-sm" data-todo-panel-nav>Edit</a>
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

    @if(filled($todo->description))
        <p class="todo-panel__description">{{ $todo->description }}</p>
    @endif

    <dl class="todo-panel__meta">
        <div>
            <dt>Assignee</dt>
            <dd>{{ $todo->assignee?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Category</dt>
            <dd>{{ $todo->category?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Due</dt>
            <dd>{{ $todo->due_at ? display_app_datetime_24($todo->due_at) : '—' }}</dd>
        </div>
        <div>
            <dt>Reminder</dt>
            <dd>{{ $pendingReminder?->remind_at ? display_app_datetime_24($pendingReminder->remind_at) : '—' }}</dd>
        </div>
        <div>
            <dt>Creator</dt>
            <dd>{{ $todo->creator?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Completed</dt>
            <dd>{{ $todo->completed_at ? display_app_datetime_24($todo->completed_at) : '—' }}</dd>
        </div>
        <div>
            <dt>Created</dt>
            <dd>{{ display_app_datetime_24($todo->created_at) }}</dd>
        </div>
    </dl>

    @can('assign', $todo)
        @if($todo->status === \App\Enums\TodoStatus::Open && $assignableUsers->isNotEmpty())
            <form method="POST" action="{{ route('todos.assign', $todo) }}" class="todo-panel__assign">
                @csrf
                <label for="assigned_to" class="form-label form-label-sm mb-1">Assign</label>
                <div class="d-flex gap-2">
                    <select id="assigned_to" name="assigned_to"
                            class="form-select form-select-sm @error('assigned_to') is-invalid @enderror" required>
                        @foreach($assignableUsers as $user)
                            <option value="{{ $user->id }}" @selected((int) $todo->assigned_to === (int) $user->id)>
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary flex-shrink-0">Update</button>
                </div>
                @error('assigned_to')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </form>
        @endif
    @endcan
</div>
