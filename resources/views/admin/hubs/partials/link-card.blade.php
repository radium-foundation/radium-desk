@props([
    'href',
    'icon',
    'title',
    'description',
])

<a href="{{ $href }}" class="card border-0 shadow-sm h-100 text-decoration-none administration-hub-card operations-card-hover">
    <div class="card-body d-flex flex-column gap-2">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <span class="administration-hub-card__icon text-primary" aria-hidden="true">
                <i class="bi {{ $icon }} fs-4"></i>
            </span>
            <i class="bi bi-arrow-up-right text-muted small" aria-hidden="true"></i>
        </div>
        <div>
            <h2 class="h6 mb-1 text-body">{{ $title }}</h2>
            <p class="text-muted small mb-0">{{ $description }}</p>
        </div>
    </div>
</a>
