@props([
    'incident',
    'order' => null,
    'customer' => [],
    'overflowMenuGroups' => [],
])

@php
    $phone = trim((string) ($customer['mobile'] ?? ''));
    $resolvedOrder = $order ?? $incident->order;
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    $whatsappUrl = strlen($phoneDigits) >= 10
        ? 'https://wa.me/'.(str_starts_with($phoneDigits, '91') ? $phoneDigits : '91'.$phoneDigits)
        : null;
    $overflowMenuGroups = $overflowMenuGroups ?? [];
    $hasOverflowMenu = collect($overflowMenuGroups)->contains(
        fn (array $group): bool => ($group['items'] ?? []) !== [],
    );
@endphp

<nav {{ $attributes->merge(['class' => 'c360-quick-toolbar']) }}
     data-customer-360-section="quick-actions"
     data-c360-quick-toolbar
     aria-label="Quick actions">
    <div class="c360-quick-toolbar-actions">
        <x-bonvoice.call-button
            :phone="$phone"
            :order-id="$resolvedOrder?->id"
            :incident-id="$incident->id"
            class="c360-quick-toolbar-btn"
            title="Call customer (C)"
            aria-label="Call customer"
            shortcut-action="call"
        />

        @if($whatsappUrl)
            <a href="{{ $whatsappUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               class="c360-quick-toolbar-btn"
               title="Open WhatsApp (W)"
               aria-label="Open WhatsApp"
               data-c360-shortcut-action="whatsapp">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                <span>WhatsApp</span>
            </a>
        @else
            <button type="button"
                    class="c360-quick-toolbar-btn"
                    disabled
                    title="No phone number"
                    aria-label="WhatsApp unavailable"
                    data-c360-shortcut-action="whatsapp">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                <span>WhatsApp</span>
            </button>
        @endif

        <button type="button"
                class="c360-quick-toolbar-btn"
                disabled
                title="Email integration coming soon"
                aria-label="Email unavailable"
                data-c360-shortcut-action="email">
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <span>Email</span>
        </button>

        @if($hasOverflowMenu)
        <div class="c360-quick-toolbar-more-wrap" data-c360-quick-more-wrap>
            <button type="button"
                    class="c360-quick-toolbar-btn c360-quick-toolbar-btn--more"
                    data-c360-quick-more-toggle
                    aria-expanded="false"
                    aria-haspopup="true"
                    aria-controls="c360-quick-more-{{ $incident->id }}">
                <i class="bi bi-three-dots" aria-hidden="true"></i>
                <span>More</span>
            </button>
            <div class="c360-quick-toolbar-more-menu"
                 id="c360-quick-more-{{ $incident->id }}"
                 data-c360-quick-more-menu
                 role="menu"
                 hidden>
                @include('customer-360.partials.overflow-menu', [
                    'overflowMenuGroups' => $overflowMenuGroups,
                    'incident' => $incident,
                ])
            </div>
        </div>
        @endif
    </div>
</nav>
