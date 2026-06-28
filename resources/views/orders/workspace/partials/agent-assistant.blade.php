@props([
    'order',
])

@php
    $assistant = [
        'customerQuestion' => 'When will my device be ready for pickup?',
        'summary' => 'Repair in progress. Payment complete. Pickup not yet scheduled.',
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

    <section class="order-workspace-assistant-section order-workspace-assistant-section--highlight">
        <h3 class="order-workspace-assistant-section-title">Customer Question</h3>
        <p class="order-workspace-assistant-text" data-assistant-field="customer-question">
            {{ $assistant['customerQuestion'] }}
        </p>
    </section>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Summary</h3>
        <p class="order-workspace-assistant-text" data-assistant-field="summary">
            {{ $assistant['summary'] }}
        </p>
    </section>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Suggested Response</h3>
        <div class="order-workspace-assistant-suggestion" data-assistant-field="suggested-response">
            {{ $assistant['suggestedResponse'] }}
        </div>
        <div class="order-workspace-assistant-send-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Coming soon">
                <i class="bi bi-whatsapp me-1"></i> WhatsApp
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Coming soon">
                <i class="bi bi-envelope me-1"></i> Email
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" disabled title="Coming soon">
                <i class="bi bi-clipboard me-1"></i> Copy
            </button>
        </div>
    </section>

    <section class="order-workspace-assistant-section order-workspace-assistant-section--nba">
        <h3 class="order-workspace-assistant-section-title">Next Best Action</h3>
        <div class="order-workspace-assistant-action" data-assistant-field="next-action">
            <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
            {{ $assistant['nextAction'] }}
        </div>
    </section>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Quick Information</h3>
        <dl class="order-workspace-assistant-info" data-assistant-field="quick-info">
            @foreach($assistant['quickInfo'] as $item)
                <div class="order-workspace-assistant-info-row">
                    <dt>{{ $item['label'] }}</dt>
                    <dd>{{ $item['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="order-workspace-assistant-future" data-assistant-ai-placeholder>
        <i class="bi bi-cpu" aria-hidden="true"></i>
        <span>AI integration placeholder — responses will require agent approval</span>
    </div>
</aside>
