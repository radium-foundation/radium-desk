@props([
    'stats',
    'variant' => 'agent',
])

@can('viewAny', \App\Models\Todo::class)
    @php
        $todoWidget = $stats['todo_widget'] ?? ['pending' => 0, 'overdue' => 0];
        $pending = (int) ($todoWidget['pending'] ?? 0);
        $overdue = (int) ($todoWidget['overdue'] ?? 0);
    @endphp

    @if($variant === 'agent')
        <button type="button"
                class="agent-kpi-tile agent-kpi-tile--todos"
                data-todo-modal-open
                data-todo-url="{{ route('todos.index') }}"
                aria-label="Open to-dos">
            <span class="agent-kpi-tile__title">To-Do</span>
            <span class="agent-kpi-tile__value">{{ number_format($pending) }}</span>
            <span class="agent-kpi-tile__meta">
                @if($overdue > 0)
                    {{ number_format($overdue) }} overdue
                @else
                    Pending tasks
                @endif
            </span>
        </button>
    @else
        <button type="button"
                @class([
                    'dashboard-kpi-item',
                    'dashboard-u-surface-card',
                    'dashboard-u-transition',
                    'dashboard-u-hover-lift',
                    'dashboard-u-focus-ring',
                    'border-0',
                    'text-start',
                    'w-100',
                ])
                data-todo-modal-open
                data-todo-url="{{ route('todos.index') }}"
                aria-label="Open to-dos">
            <div class="dashboard-kpi-icon text-primary">
                <i class="bi bi-check2-square" aria-hidden="true"></i>
            </div>
            <div class="dashboard-kpi-content">
                <div class="dashboard-kpi-label">To-Do</div>
                <div class="dashboard-kpi-value">{{ number_format($pending) }}</div>
                <div class="small text-muted">
                    @if($overdue > 0)
                        {{ number_format($overdue) }} overdue
                    @else
                        Pending tasks
                    @endif
                </div>
            </div>
        </button>
    @endif
@endcan
