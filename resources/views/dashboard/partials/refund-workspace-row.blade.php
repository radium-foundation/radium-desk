@php
    $searchParts = array_filter([
        $refund->reference_no,
        $refund->order?->order_id,
        $refund->incident?->display_reference,
        $refund->requester?->name,
    ], fn ($value) => filled($value));
    $searchText = strtolower(implode(' ', $searchParts));
@endphp

<tr id="refund-workspace-row-{{ $refund->id }}"
    data-refund-id="{{ $refund->id }}"
    data-search-text="{{ e($searchText) }}"
    class="dashboard-case-row--clickable">
    <td class="case-reference-cell">
        <a href="{{ route('refunds.show', $refund) }}" class="case-reference-link text-decoration-none">
            {{ $refund->reference_no }}
        </a>
    </td>
    <td class="case-order-cell case-meta-cell">
        @if($refund->order)
            <a href="{{ route('orders.show', $refund->order) }}" class="text-decoration-none">
                {{ $refund->order->order_id }}
            </a>
        @else
            —
        @endif
    </td>
    <td class="case-meta-cell d-none d-md-table-cell">
        @if($refund->incident)
            <a href="{{ route('incidents.show', $refund->incident) }}" class="text-decoration-none">
                {{ $refund->incident->display_reference }}
            </a>
        @else
            —
        @endif
    </td>
    <td class="case-meta-cell text-nowrap">{{ number_format((float) $refund->amount, 2) }}</td>
    <td class="status-cell">
        @include('refunds.partials.status-badge', ['status' => $refund->status])
    </td>
    <td class="dashboard-people-cell d-none d-md-table-cell">
        {{ $refund->requester?->name ?? '—' }}
    </td>
    <td class="dashboard-date-cell d-none d-lg-table-cell text-nowrap">
        {{ display_app_datetime_24($refund->created_at) }}
    </td>
    <td class="text-end">
        <a href="{{ route('refunds.show', $refund) }}"
           class="btn btn-sm btn-outline-primary dashboard-u-focus-ring"
           title="View">
            <i class="bi bi-eye" aria-hidden="true"></i>
        </a>
    </td>
</tr>
