@props([
    'variant' => 'info',
    'icon' => null,
])

<span {{ $attributes->merge(['class' => 'c360-status-banner c360-status-banner--' . $variant]) }}
      role="status">
    @if(filled($icon))
        <span class="c360-status-banner-icon" aria-hidden="true">{{ $icon }}</span>
    @endif
    <span class="c360-status-banner-text">{{ $slot }}</span>
</span>
