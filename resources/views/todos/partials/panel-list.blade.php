@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\Todo> $todos */
    /** @var array{status: string, scope: string, category: string} $filters */
    /** @var \Illuminate\Support\Collection<int, \App\Models\TodoCategory> $categories */
    $categories = $categories ?? collect();
@endphp

<div class="todo-panel" data-todo-panel="list">
    <div class="todo-panel__toolbar">
        <form method="GET" action="{{ route('todos.index') }}" class="todo-panel__filters" data-todo-panel-nav>
            <select name="status" class="form-select form-select-sm" aria-label="Status" onchange="this.form.requestSubmit()">
                <option value="" @selected(($filters['status'] ?? '') === '')>Open</option>
                @foreach(\App\Enums\TodoStatus::cases() as $statusOption)
                    <option value="{{ $statusOption->value }}" @selected(($filters['status'] ?? '') === $statusOption->value)>
                        {{ $statusOption->label() }}
                    </option>
                @endforeach
                <option value="all" @selected(($filters['status'] ?? '') === 'all')>All</option>
            </select>
            <select name="scope" class="form-select form-select-sm" aria-label="Scope" onchange="this.form.requestSubmit()">
                <option value="" @selected(($filters['scope'] ?? '') === '')>Mine</option>
                <option value="assigned" @selected(($filters['scope'] ?? '') === 'assigned')>Assigned to me</option>
                <option value="created" @selected(($filters['scope'] ?? '') === 'created')>Created by me</option>
            </select>
            @if($categories->isNotEmpty())
                <select name="category" class="form-select form-select-sm" aria-label="Category" onchange="this.form.requestSubmit()">
                    <option value="" @selected(($filters['category'] ?? '') === '')>All categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['category'] ?? '') === (string) $category->id)>
                            {{ $category->name }}@if(! $category->is_active) (inactive)@endif
                        </option>
                    @endforeach
                </select>
            @endif
        </form>

        <div class="todo-panel__toolbar-actions">
            @can('viewAny', \App\Models\TodoCategory::class)
                <a href="{{ route('todo-categories.index') }}" class="btn btn-sm btn-outline-secondary">
                    Categories
                </a>
            @endcan
            @can('create', \App\Models\Todo::class)
                <a href="{{ route('todos.create') }}" class="btn btn-sm btn-primary" data-todo-panel-nav>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>New</span>
                </a>
            @endcan
        </div>
    </div>

    <div class="todo-panel__list" role="list">
        @forelse($todos as $todo)
            @php
                $pendingReminder = $todo->pendingReminder();
                $isSubdued = in_array($todo->status, [\App\Enums\TodoStatus::Completed, \App\Enums\TodoStatus::Cancelled], true);
            @endphp
            <a
                href="{{ route('todos.show', $todo) }}"
                class="todo-panel__row {{ $isSubdued ? 'is-subdued' : '' }} {{ $todo->isOverdue() ? 'is-overdue' : '' }}"
                role="listitem"
                data-todo-panel-nav
            >
                <div class="todo-panel__row-main">
                    <div class="todo-panel__row-title">
                        {{ $todo->title }}
                        @if($todo->isOverdue())
                            <span class="badge text-bg-danger">Overdue</span>
                        @endif
                    </div>
                    <div class="todo-panel__row-meta">
                        @include('todos.partials.priority-badge', ['priority' => $todo->priority])
                        @if($todo->category)
                            <span>{{ $todo->category->name }}</span>
                        @endif
                        <span>{{ $todo->assignee?->name ?? 'Unassigned' }}</span>
                        @if($todo->due_at)
                            <span>Due {{ display_app_datetime_24($todo->due_at) }}</span>
                        @endif
                        @if($pendingReminder?->remind_at)
                            <span>Remind {{ display_app_datetime_24($pendingReminder->remind_at) }}</span>
                        @endif
                    </div>
                </div>
                <div class="todo-panel__row-status">
                    @include('todos.partials.status-badge', ['status' => $todo->status])
                </div>
            </a>
        @empty
            <div class="todo-panel__empty">
                <p class="mb-1">No to-dos match these filters.</p>
                @can('create', \App\Models\Todo::class)
                    <a href="{{ route('todos.create') }}" class="btn btn-sm btn-outline-primary" data-todo-panel-nav>Create one</a>
                @endcan
            </div>
        @endforelse
    </div>

    @if($todos->hasPages())
        <div class="todo-panel__pager" data-todo-panel-nav>
            {{ $todos->onEachSide(0)->links('pagination::simple-bootstrap-5') }}
        </div>
    @endif
</div>
