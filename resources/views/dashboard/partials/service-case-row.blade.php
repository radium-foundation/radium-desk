@php
    $order = $serviceCase->order;
    $isCompleted = $order?->isTransactionLocked() ?? false;
@endphp

<tr id="service-case-row-{{ $serviceCase->id }}"
    data-incident-id="{{ $serviceCase->id }}"
    data-order-id="{{ $order?->id }}"
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
    <td class="case-meta-cell d-none d-md-table-cell">{{ $order?->order_id ?: '—' }}</td>
    <td class="case-meta-cell d-none d-lg-table-cell">{{ $order?->serial_number ?: '—' }}</td>
    <td class="case-meta-cell d-none d-lg-table-cell">{{ $order?->product_name ?: '—' }}</td>
    <td class="source-cell d-none d-sm-table-cell">
        @include('dashboard.partials.source-icon', ['source' => $serviceCase->source])
    </td>
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
    <td class="case-meta-cell d-none d-sm-table-cell">{{ $serviceCase->assignee?->firstName() ?: '—' }}</td>
    <td class="case-meta-cell d-none d-sm-table-cell">{{ $serviceCase->creator?->firstName() ?: '—' }}</td>
    <td class="case-meta-cell text-nowrap">{{ display_app_datetime($serviceCase->created_at) }}</td>
    <td class="case-meta-cell d-none d-md-table-cell text-nowrap">{{ display_app_datetime($serviceCase->updated_at) }}</td>
    @if($canReassignServiceCases ?? false)
        <td class="dashboard-actions-cell text-end text-nowrap">
            @can('reassign', $serviceCase)
                <button type="button"
                        class="btn btn-outline-primary btn-sm"
                        data-workspace-trigger="assign"
                        data-workspace-incident-id="{{ $serviceCase->id }}"
                        data-workspace-context="dashboard"
                        aria-label="Assign {{ $serviceCase->display_reference }}">
                    <i class="bi bi-person-check"></i>
                    <span class="d-none d-xl-inline ms-1">Assign</span>
                </button>
            @endcan
        </td>
    @endif
</tr>
