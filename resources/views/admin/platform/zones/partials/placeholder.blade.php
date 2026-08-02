@props([
    'title' => '',
    'message' => '',
    'zoneKey' => '',
])

<div class="platform-zone-placeholder text-muted small" data-platform-zone-placeholder="{{ $zoneKey }}">
    <p class="mb-0">{{ $message }}</p>
</div>
