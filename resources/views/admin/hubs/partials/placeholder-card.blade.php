@props([
    'icon',
    'title',
    'description',
    'badge' => 'Coming Soon',
])

<div class="card border-0 shadow-sm h-100 administration-hub-card administration-hub-card--placeholder">
    <div class="card-body d-flex flex-column gap-2">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <span class="administration-hub-card__icon text-muted" aria-hidden="true">
                <i class="bi {{ $icon }} fs-4"></i>
            </span>
            <span class="badge text-bg-secondary">{{ $badge }}</span>
        </div>
        <div>
            <h2 class="h6 mb-1 text-body">{{ $title }}</h2>
            <p class="text-muted small mb-0">{{ $description }}</p>
        </div>
    </div>
</div>
