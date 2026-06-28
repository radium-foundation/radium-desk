@props([
    'variant' => 'tab',
])

@if($variant === 'sidebar')
    <div class="order-workspace-comm-summary order-workspace-comm-summary--sidebar">
        <p class="order-workspace-comm-summary-empty mb-0">
            No customer communication has been logged.
        </p>
        <button type="button"
                class="btn btn-link btn-sm p-0 order-workspace-summary-link-btn mt-1"
                data-workspace-tab-trigger="communication">
            View communication
        </button>
    </div>
@else
    <div class="order-workspace-comm-summary order-workspace-comm-summary--tab">
        <div class="order-workspace-empty">
            <i class="bi bi-chat-dots" aria-hidden="true"></i>
            <p class="mb-0">No customer communication has been logged.</p>
            <p class="text-muted small mb-0 mt-1">Communication integration is planned for a future release.</p>
        </div>
    </div>
@endif
