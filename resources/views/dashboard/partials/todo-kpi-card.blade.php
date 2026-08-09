@props([
    'stats',
    'variant' => 'agent',
])

@can('viewAny', \App\Models\Todo::class)
    @php
        $todoWidget = $stats['todo_widget'] ?? ['pending' => 0, 'overdue' => 0];
        $pending = (int) ($todoWidget['pending'] ?? 0);
        $overdue = (int) ($todoWidget['overdue'] ?? 0);
        $subtitle = $overdue > 0
            ? number_format($overdue).' overdue'
            : 'Pending tasks';
    @endphp

    @if($variant === 'agent')
        <button type="button"
                class="agent-kpi-tile agent-kpi-tile--todos"
                data-todo-modal-open
                data-todo-url="{{ route('todos.index') }}"
                aria-label="Open to-dos">
            <span class="agent-kpi-tile__title">To-Do</span>
            <span class="agent-kpi-tile__value">{{ number_format($pending) }}</span>
            <span class="agent-kpi-tile__meta">{{ $subtitle }}</span>
        </button>
    @else
        <button type="button"
                @class([
                    'dashboard-kpi-item',
                    'dashboard-u-surface-card',
                    'dashboard-u-transition',
                    'dashboard-u-hover-lift',
                    'dashboard-u-focus-ring',
                    'dashboard-todo-kpi',
                    'border-0',
                    'text-start',
                ])
                data-todo-modal-open
                data-todo-url="{{ route('todos.index') }}"
                aria-label="Open to-dos">
            <div class="dashboard-todo-kpi__body">
                <div class="dashboard-todo-kpi__title">To-Do</div>
                <div class="dashboard-todo-kpi__value">{{ number_format($pending) }}</div>
                <div class="dashboard-todo-kpi__subtitle">{{ $subtitle }}</div>
            </div>
        </button>
    @endif
@endcan
