<div class="card stat-card h-100">
    <div class="card-body d-flex align-items-center">
        <div class="stat-icon bg-{{ $color }}-subtle text-{{ $color }} me-3">
            <i class="bi {{ $icon }}"></i>
        </div>
        <div>
            <div class="text-muted small">{{ $label }}</div>
            <div class="fs-3 fw-semibold">{{ number_format($value) }}</div>
        </div>
    </div>
</div>
