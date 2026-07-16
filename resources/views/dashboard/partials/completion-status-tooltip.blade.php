@props(['order', 'loggedAt'])

@php
    use App\Enums\OrderCompletionStatus;
    use App\Models\Order;
    use App\Support\AppDateFormatter;

    $referenceCreatedAt = $loggedAt ?? $order->created_at ?? now();
    $status = $order->completionStatus();
    $tooltipAriaLabel = $status === OrderCompletionStatus::PendingAdmin
        ? 'Pending Admin'
        : $status->label();
@endphp

@include('orders.partials.completion-status-badge', [
    'order' => $order,
    'iconOnly' => true,
    'dashboardTooltip' => true,
    'tooltipAriaLabel' => $tooltipAriaLabel,
])
<template class="dashboard-tooltip-template">
    @if($status === OrderCompletionStatus::PendingAdmin)
        @include('dashboard.partials.premium-tooltip', [
            'title' => 'Waiting for Service Reference',
            'sections' => [
                [
                    'label' => 'Created',
                    'value' => AppDateFormatter::datetime($referenceCreatedAt) ?? '—',
                ],
                [
                    'label' => 'Pending',
                    'value' => Order::formatDurationBetween($referenceCreatedAt) ?? '—',
                ],
            ],
        ])
    @else
        @include('dashboard.partials.premium-tooltip', [
            'sections' => [
                [
                    'label' => 'Service Reference',
                    'value' => $order->transaction_id ?: '—',
                ],
                [
                    'label' => 'Completed',
                    'value' => AppDateFormatter::datetime($order->completed_at) ?? '—',
                ],
                [
                    'label' => 'Total turnaround',
                    'value' => Order::formatDurationBetween($referenceCreatedAt, $order->completed_at) ?? '—',
                ],
            ],
        ])
    @endif
</template>
