@props([
    'compact' => false,
])

@php
    $channels = [
        ['key' => 'call', 'label' => 'Last Call', 'icon' => 'bi-telephone', 'empty' => 'No calls logged yet'],
        ['key' => 'whatsapp', 'label' => 'Last WhatsApp', 'icon' => 'bi-whatsapp', 'empty' => 'No WhatsApp messages yet'],
        ['key' => 'email', 'label' => 'Last Email', 'icon' => 'bi-envelope', 'empty' => 'No emails sent yet'],
    ];
@endphp

<div @class([
    'order-workspace-comm-summary',
    'order-workspace-comm-summary--compact' => $compact,
])>
    @foreach($channels as $channel)
        <div class="order-workspace-comm-summary-item">
            <div class="order-workspace-comm-summary-label">
                <i class="bi {{ $channel['icon'] }}" aria-hidden="true"></i>
                {{ $channel['label'] }}
            </div>
            <div class="order-workspace-comm-summary-value text-muted">
                {{ $channel['empty'] }}
            </div>
        </div>
    @endforeach

    <div class="order-workspace-comm-summary-cta">
        <button type="button"
                class="btn btn-sm btn-outline-primary w-100"
                disabled
                title="Coming soon">
            <i class="bi bi-telephone-outbound me-1"></i> Log first contact
        </button>
    </div>
</div>
