@props([
    'order',
])

@php
    $openCases = $order->openIncidentsCount();
    $totalCases = $order->incidents_count;
@endphp

<aside class="order-workspace-assistant"
       aria-label="Agent assistant"
       data-agent-assistant
       data-order-id="{{ $order->order_id }}">
    <div class="order-workspace-assistant-header">
        <i class="bi bi-stars" aria-hidden="true"></i>
        <h2 class="order-workspace-assistant-title">Agent Assistant</h2>
    </div>

    {{-- Reserved for future AI — hidden until backed by real data --}}
    <section class="order-workspace-assistant-section order-workspace-assistant-section--highlight"
             data-assistant-slot="customer-question"
             hidden
             aria-hidden="true">
        <h3 class="order-workspace-assistant-section-title">Customer Question</h3>
        <p class="order-workspace-assistant-text" data-assistant-field="customer-question"></p>
    </section>

    <section class="order-workspace-assistant-section"
             data-assistant-slot="summary"
             hidden
             aria-hidden="true">
        <h3 class="order-workspace-assistant-section-title">Summary</h3>
        <p class="order-workspace-assistant-text" data-assistant-field="summary"></p>
    </section>

    <section class="order-workspace-assistant-section"
             data-assistant-slot="suggested-response"
             hidden
             aria-hidden="true">
        <h3 class="order-workspace-assistant-section-title">Suggested Response</h3>
        <div class="order-workspace-assistant-suggestion" data-assistant-field="suggested-response"></div>
        <div class="order-workspace-assistant-send-actions" data-assistant-slot="send-actions"></div>
    </section>

    <section class="order-workspace-assistant-section order-workspace-assistant-section--nba"
             data-assistant-slot="next-action"
             hidden
             aria-hidden="true">
        <h3 class="order-workspace-assistant-section-title">Next Best Action</h3>
        <div class="order-workspace-assistant-action" data-assistant-field="next-action"></div>
    </section>

    <section class="order-workspace-assistant-section">
        <h3 class="order-workspace-assistant-section-title">Service Cases</h3>
        <dl class="order-workspace-assistant-info" data-assistant-field="quick-info">
            <div class="order-workspace-assistant-info-row">
                <dt>Open</dt>
                <dd>{{ (string) $openCases }}</dd>
            </div>
            <div class="order-workspace-assistant-info-row">
                <dt>Total</dt>
                <dd>{{ (string) $totalCases }}</dd>
            </div>
        </dl>
    </section>

    <p class="order-workspace-assistant-empty">
        Guided responses and recommendations will appear when connected to operational data and AI services.
    </p>
</aside>
