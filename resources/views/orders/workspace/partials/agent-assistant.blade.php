@props([
    'order',
])

@php
    $assistant = [
        'customerAsking' => 'When will my device be ready for pickup?',
        'suggestedResponse' => 'Thank you for checking in. Your repair is currently in progress with our engineer. We will notify you as soon as it is ready for pickup.',
        'nextAction' => 'Confirm repair status with the assigned engineer and update the customer.',
        'quickInfo' => [
            ['label' => 'Open service cases', 'value' => (string) $order->openIncidentsCount()],
            ['label' => 'Total service cases', 'value' => (string) $order->incidents_count],
            ['label' => 'Payment status', 'value' => $order->isTransactionLocked() ? 'Complete' : 'Pending'],
        ],
    ];
@endphp

<aside class="order-workspace-assistant"
       aria-label="Agent assistant"
       data-agent-assistant
       data-order-id="{{ $order->order_id }}">
    <div class="order-workspace-assistant-header">
        <i class="bi bi-stars" aria-hidden="true"></i>
        <h2 class="order-workspace-assistant-title">Agent Assistant</h2>
    </div>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Customer Asking About</h3>
        <p class="order-workspace-assistant-text" data-assistant-field="customer-asking">
            {{ $assistant['customerAsking'] }}
        </p>
    </section>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Suggested Response</h3>
        <div class="order-workspace-assistant-suggestion" data-assistant-field="suggested-response">
            {{ $assistant['suggestedResponse'] }}
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" disabled title="Coming soon">
            <i class="bi bi-clipboard me-1"></i> Copy
        </button>
    </section>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Next Recommended Action</h3>
        <div class="order-workspace-assistant-action" data-assistant-field="next-action">
            <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
            {{ $assistant['nextAction'] }}
        </div>
    </section>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Quick Info</h3>
        <dl class="order-workspace-assistant-info" data-assistant-field="quick-info">
            @foreach($assistant['quickInfo'] as $item)
                <div class="order-workspace-assistant-info-row">
                    <dt>{{ $item['label'] }}</dt>
                    <dd>{{ $item['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="order-workspace-assistant-future">
        <i class="bi bi-cpu" aria-hidden="true"></i>
        <span>AI widgets will appear here</span>
    </div>
</aside>
