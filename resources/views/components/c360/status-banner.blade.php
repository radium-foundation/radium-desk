@props([
    'variant' => 'info',
    'icon' => 'ⓘ',
])

<span {{ $attributes->merge(['class' => 'c360-status-banner c360-status-banner--' . $variant]) }}
      role="status">
    <span class="c360-status-banner-icon" aria-hidden="true">{{ $icon }}</span>
    <span class="c360-status-banner-text">{{ $slot }}</span>
</span>
