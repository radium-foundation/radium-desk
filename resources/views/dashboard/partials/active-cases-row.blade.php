@php
    $order = $incident->order;
    $searchParts = array_filter([
        $order?->order_id,
        $incident->display_reference,
        $incident->title,
        $incident->category,
        $order?->customer_name,
    ], fn ($value) => filled($value));
    $searchText = strtolower(implode(' ', $searchParts));
@endphp

<tr id="active-case-row-{{ $incident->id }}"
    data-incident-id="{{ $incident->id }}"
    data-search-text="{{ e($searchText) }}"
    class="dashboard-case-row--clickable">
    <td class="case-reference-cell">
        <div class="d-flex flex-wrap align-items-center gap-1">
            <a href="{{ route('incidents.show', $incident) }}" class="case-reference-link text-decoration-none">
                {{ $incident->display_reference }}
            </a>
            @if($incident->high_priority)
                @include('dashboard.partials.high-priority-badge')
            @endif
        </div>
    </td>
    <td class="case-order-cell case-meta-cell">
        @if($order)
            <x-order-identifier
                :order="$order"
                :incident="$incident"
                :href="$order->isInquiryOrder() ? null : route('orders.show', $order)"
            />
        @else
            —
        @endif
    </td>
    <td class="case-meta-cell">{{ Str::limit($incident->title, 40) }}</td>
    <td class="case-meta-cell d-none d-md-table-cell">{{ $incident->category }}</td>
    <td class="source-cell d-none d-md-table-cell">
        @include('dashboard.partials.source-icon', ['source' => $incident->source])
    </td>
    <td class="status-cell">
        @include('incidents.partials.status-badge', ['status' => $incident->status])
    </td>
    <td class="dashboard-date-cell d-none d-lg-table-cell text-nowrap">
        {{ display_app_date($incident->created_at) }}
    </td>
    <td class="text-end">
        <div class="btn-group btn-group-sm">
            <a href="{{ route('incidents.show', $incident) }}"
               class="btn btn-outline-primary dashboard-u-focus-ring"
               title="View">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </a>
            @can('update', $incident)
                <a href="{{ route('incidents.edit', $incident) }}"
                   class="btn btn-outline-secondary dashboard-u-focus-ring"
                   title="Edit">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
            @endcan
        </div>
    </td>
</tr>
