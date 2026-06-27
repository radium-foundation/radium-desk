@props(['order', 'loggedAt'])

@php
    use App\Enums\OrderCompletionStatus;
    use App\Models\Order;
    use App\Support\AppDateFormatter;

    $referenceCreatedAt = $loggedAt ?? $order->created_at ?? now();
    $status = $order->completionStatus();

    if ($status === OrderCompletionStatus::PendingAdmin) {
        $tooltipHtml = view('dashboard.partials.premium-tooltip', [
            'title' => 'Waiting for Transaction ID',
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
        ])->render();
    } else {
        $tooltipHtml = view('dashboard.partials.premium-tooltip', [
            'sections' => [
                [
                    'label' => 'Transaction ID',
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
        ])->render();
    }
@endphp

<span class="d-inline-flex align-items-center gap-1">
    @include('orders.partials.completion-status-badge', ['order' => $order])

    <i class="bi bi-info-circle dashboard-status-info-icon"
       role="img"
       aria-label="Completion status details"
       data-bs-toggle="tooltip"
       data-bs-placement="top"
       data-bs-html="true"
       data-bs-custom-class="dashboard-premium-tooltip-wrapper"
       data-bs-title="{{ $tooltipHtml }}"></i>
</span>
