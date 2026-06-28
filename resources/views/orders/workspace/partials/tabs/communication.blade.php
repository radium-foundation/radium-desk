<div class="order-workspace-communication-grid">
    @component('orders.workspace.partials.info-card', ['title' => 'Recent Calls', 'icon' => 'bi-telephone'])
        <div class="order-workspace-empty order-workspace-empty--inline">
            <i class="bi bi-telephone-outbound" aria-hidden="true"></i>
            <p class="mb-0">No call records yet. Integration coming soon.</p>
        </div>
    @endcomponent

    @component('orders.workspace.partials.info-card', ['title' => 'Recent WhatsApp', 'icon' => 'bi-whatsapp'])
        <div class="order-workspace-empty order-workspace-empty--inline">
            <i class="bi bi-whatsapp" aria-hidden="true"></i>
            <p class="mb-0">No WhatsApp messages yet. Integration coming soon.</p>
        </div>
    @endcomponent

    @component('orders.workspace.partials.info-card', ['title' => 'Recent Emails', 'icon' => 'bi-envelope'])
        <div class="order-workspace-empty order-workspace-empty--inline">
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <p class="mb-0">No emails yet. Integration coming soon.</p>
        </div>
    @endcomponent
</div>
