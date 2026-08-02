@props([
    'label' => 'Item',
    'message' => 'Unable to load details.',
])

<div class="alert alert-secondary mb-0" role="status">
    <strong>{{ $label }}</strong>
    <p class="mb-0 small">{{ $message }}</p>
</div>
