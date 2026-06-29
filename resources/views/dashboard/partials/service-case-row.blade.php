@php
    use App\Enums\IncidentStatus;

    $order = $serviceCase->order;
    $isCompleted = $order?->isTransactionLocked() ?? false;
    $canResolve = auth()->user()?->can('update', $serviceCase)
        && ! in_array($serviceCase->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true);
    $canClose = auth()->user()?->can('update', $serviceCase)
        && $serviceCase->status !== IncidentStatus::Closed;
    $canShowRowActions = (auth()->user()?->can('create', \App\Models\Remark::class) && auth()->user()?->can('view', $serviceCase))
        || auth()->user()?->can('reassign', $serviceCase)
        || $canResolve
        || $canClose;
    $searchParts = array_filter([
        $order?->order_id,
        $serviceCase->display_reference,
        $order?->customer_name,
        $order?->customer_phone,
        $order?->serial_number,
        $order?->transaction_id,
        $order?->displayDeviceModelName(),
    ], fn ($value) => filled($value));
    $searchText = strtolower(implode(' ', $searchParts));
@endphp

<tr id="service-case-row-{{ $serviceCase->id }}"
    data-incident-id="{{ $serviceCase->id }}"
    data-order-id="{{ $order?->id }}"
    data-search-text="{{ e($searchText) }}"
    @class([
        'dashboard-case-row--completed' => $isCompleted,
        'dashboard-case-row--pending' => $order && ! $isCompleted,
    ])>
    @if($canSelectRows ?? false)
        <td class="dashboard-select-cell">
            @if($order && ! $isCompleted)
                <input type="checkbox"
                       class="form-check-input service-case-select"
                       value="{{ $serviceCase->id }}"
                       data-order-id="{{ $order->id }}"
                       aria-label="Select {{ $serviceCase->display_reference }}">
            @endif
        </td>
    @endif
    <td class="case-reference-cell">
        <div class="d-flex flex-wrap align-items-center gap-1">
            <a href="{{ route('incidents.show', $serviceCase) }}" class="case-reference-link text-decoration-none">
                {{ $serviceCase->display_reference }}
            </a>
            @if($serviceCase->high_priority)
                @include('dashboard.partials.high-priority-badge')
            @endif
        </div>
    </td>
    <td class="case-order-cell case-meta-cell">
        @if($order)
            <a href="{{ route('orders.show', $order) }}" class="text-decoration-none">{{ $order->order_id }}</a>
        @else
            —
        @endif
    </td>
    @include('dashboard.partials.serial-number-cell', ['serviceCase' => $serviceCase])
    <td class="status-cell">
        @if($order)
            @include('dashboard.partials.completion-status-tooltip', [
                'order' => $order,
                'loggedAt' => $serviceCase->created_at,
            ])
        @else
            —
        @endif
    </td>
    <td class="sla-cell">
        @include('dashboard.partials.sla-status', ['serviceCase' => $serviceCase])
    </td>
    @include('dashboard.partials.transaction-id-cell', [
        'serviceCase' => $serviceCase,
        'canManageTransactions' => $canManageTransactions ?? false,
    ])
    <td class="source-cell d-none d-md-table-cell">
        @include('dashboard.partials.source-icon', ['source' => $serviceCase->source])
    </td>
    <td class="case-meta-cell dashboard-owner-cell dashboard-user-cell d-none d-md-table-cell">
        @if($serviceCase->assignee)
            <x-dashboard-user-avatar :user="$serviceCase->assignee" aria-prefix="Owner" />
        @else
            —
        @endif
    </td>
    <td class="case-meta-cell dashboard-user-cell d-none d-md-table-cell">
        @if($serviceCase->creator)
            <x-dashboard-user-avatar :user="$serviceCase->creator" aria-prefix="Logged by" />
        @else
            —
        @endif
    </td>
    <td class="case-meta-cell dashboard-date-cell d-none d-lg-table-cell">
        @if($serviceCase->created_at)
            <span class="dashboard-u-datetime-stack">
                <span class="dashboard-u-datetime-stack__date">{{ display_app_timeline_date($serviceCase->created_at) }}</span>
                <span class="dashboard-u-datetime-stack__time">{{ display_app_timeline_time($serviceCase->created_at) }}</span>
            </span>
        @else
            —
        @endif
    </td>
    <td class="case-meta-cell dashboard-date-cell d-none d-lg-table-cell">
        @if($serviceCase->updated_at)
            <span class="dashboard-u-datetime-stack">
                <span class="dashboard-u-datetime-stack__date">{{ display_app_timeline_date($serviceCase->updated_at) }}</span>
                <span class="dashboard-u-datetime-stack__time">{{ display_app_timeline_time($serviceCase->updated_at) }}</span>
            </span>
        @else
            —
        @endif
    </td>
    @include('dashboard.partials.device-model-cell', ['serviceCase' => $serviceCase])
    @if($canShowRowActions)
        <td class="dashboard-actions-cell text-end">
            <div class="dashboard-row-actions">
                @can('create', App\Models\Remark::class)
                    @can('view', $serviceCase)
                        <button type="button"
                                class="dashboard-u-icon-action dashboard-u-transition dashboard-u-focus-ring"
                                data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                data-bs-title="Remark"
                                data-workspace-trigger="remark"
                                data-workspace-incident-id="{{ $serviceCase->id }}"
                                data-workspace-context="dashboard"
                                aria-label="Add remark for {{ $serviceCase->display_reference }}">
                            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                        </button>
                    @endcan
                @endcan
                @can('reassign', $serviceCase)
                    <button type="button"
                            class="dashboard-u-icon-action dashboard-u-transition dashboard-u-focus-ring"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"
                            data-bs-title="Assign"
                            data-workspace-trigger="assign"
                            data-workspace-incident-id="{{ $serviceCase->id }}"
                            data-workspace-context="dashboard"
                            aria-label="Assign {{ $serviceCase->display_reference }}">
                        <i class="bi bi-person-check" aria-hidden="true"></i>
                    </button>
                @endcan
                @if($canResolve)
                    <button type="button"
                            class="dashboard-u-icon-action dashboard-u-transition dashboard-u-focus-ring"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"
                            data-bs-title="Resolve"
                            data-workspace-trigger="resolve"
                            data-workspace-incident-id="{{ $serviceCase->id }}"
                            data-workspace-context="dashboard"
                            aria-label="Resolve {{ $serviceCase->display_reference }}">
                        <i class="bi bi-check-circle" aria-hidden="true"></i>
                    </button>
                @endif
                @if($canClose)
                    <button type="button"
                            class="dashboard-u-icon-action dashboard-u-transition dashboard-u-focus-ring"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"
                            data-bs-title="Close"
                            data-workspace-trigger="close"
                            data-workspace-incident-id="{{ $serviceCase->id }}"
                            data-workspace-context="dashboard"
                            aria-label="Close {{ $serviceCase->display_reference }}">
                        <i class="bi bi-x-circle" aria-hidden="true"></i>
                    </button>
                @endif
            </div>
        </td>
    @endif
</tr>
