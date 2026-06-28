@props([
    'order',
])

<aside class="order-workspace-left" aria-label="Customer context">
    <div class="order-workspace-summary">
        @include('orders.workspace.partials.customer-story', ['order' => $order])

        <section class="order-workspace-summary-section">
            <h3 class="order-workspace-summary-label">Latest Communication</h3>
            @include('orders.workspace.partials.communication-summary', ['variant' => 'sidebar'])
        </section>
    </div>
</aside>
