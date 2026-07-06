@props([
    'label' => 'Loading details…',
])

<div class="operations-lazy-placeholder operations-skeleton-loader card border-0 shadow-sm" aria-busy="true" aria-label="{{ $label }}">
    <div class="card-body py-3">
        <span class="visually-hidden">{{ $label }}</span>
        <div class="operations-skeleton-line operations-skeleton-line--title"></div>
        <div class="operations-skeleton-line"></div>
        <div class="operations-skeleton-line operations-skeleton-line--medium"></div>
        <div class="operations-skeleton-line operations-skeleton-line--short"></div>
    </div>
</div>
