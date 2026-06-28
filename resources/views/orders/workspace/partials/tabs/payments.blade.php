@props([
    'order',
])

@include('orders.partials.update-transaction-form', ['order' => $order])

@component('orders.workspace.partials.info-card', ['title' => 'Payment Details', 'icon' => 'bi-credit-card'])
    <dl class="order-workspace-dl order-workspace-dl--wide">
        <dt>Transaction ID</dt>
        <dd>{{ $order->transaction_id ?: '—' }}</dd>

        <dt>Payment Amount</dt>
        <dd>{{ $order->payment_amount !== null ? number_format((float) $order->payment_amount, 2) : '—' }}</dd>

        <dt>Payment Method</dt>
        <dd>{{ $order->payment_method ?: '—' }}</dd>

        <dt>Payment Date</dt>
        <dd>{{ $order->payment_date ? display_app_datetime($order->payment_date) : '—' }}</dd>

        <dt>Bank Reference</dt>
        <dd>{{ $order->bank_reference ?: '—' }}</dd>

        <dt>Completed At</dt>
        <dd>{{ $order->completed_at ? display_app_datetime($order->completed_at) : '—' }}</dd>

        <dt>Assigned By</dt>
        <dd>{{ $order->transactionAssigner?->name ?: '—' }}</dd>
    </dl>
@endcomponent
