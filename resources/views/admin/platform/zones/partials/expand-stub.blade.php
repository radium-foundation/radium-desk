@props([
    'title' => '',
    'item' => '',
    'message' => '',
])

<div class="platform-zone-expand-stub border rounded-3 p-3 bg-body-tertiary">
    <div class="fw-semibold mb-1">{{ $title }}</div>
    @if($item !== '')
        <div class="text-muted small mb-2">Item: <code>{{ $item }}</code></div>
    @endif
    <p class="text-muted small mb-0">{{ $message }}</p>
</div>
