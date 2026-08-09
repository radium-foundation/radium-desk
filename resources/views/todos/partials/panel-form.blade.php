@php
    /** @var \App\Models\Todo|null $todo */
    /** @var \Illuminate\Support\Collection<int, \App\Models\User> $assignableUsers */
    /** @var \App\Models\Reminder|null $pendingReminder */
    $isEdit = isset($todo) && $todo !== null;
@endphp

<div class="todo-panel" data-todo-panel="form">
    <div class="todo-panel__detail-nav">
        <a
            href="{{ $isEdit ? route('todos.show', $todo) : route('todos.index') }}"
            class="btn btn-sm btn-link px-0"
            data-todo-panel-nav
        >
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            {{ $isEdit ? 'Back to to-do' : 'All to-dos' }}
        </a>
    </div>

    <h2 class="todo-panel__detail-title mb-3">{{ $isEdit ? 'Edit to-do' : 'New to-do' }}</h2>

    @include('todos.partials.form', array_merge([
        'assignableUsers' => $assignableUsers,
        'pendingReminder' => $pendingReminder ?? null,
        'compact' => true,
    ], $isEdit ? ['todo' => $todo] : []))
</div>
