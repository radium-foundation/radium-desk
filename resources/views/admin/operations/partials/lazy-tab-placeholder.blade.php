@props([
    'label' => 'Loading details…',
])

<div class="operations-lazy-placeholder card border-0 shadow-sm">
    <div class="card-body py-4 text-center text-muted">
        <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
        <span>{{ $label }}</span>
    </div>
</div>
