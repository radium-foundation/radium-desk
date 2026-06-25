<div class="card stat-card h-100">
    <div class="card-body d-flex align-items-center">
        <div class="stat-icon bg-{{ $color }}-subtle text-{{ $color }}">
            <i class="bi {{ $icon }}" aria-hidden="true"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">{{ $label }}</div>
            <div class="stat-value">{{ number_format($value) }}</div>
        </div>
    </div>
</div>
