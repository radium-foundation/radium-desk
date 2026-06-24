@if(auth()->user()?->can('incidents.view') && (isset($stats['overdue_cases']) || isset($stats['warning_cases'])))
    <div class="row g-2 mb-3">
        <div class="col-md-6">
            <a href="{{ route('dashboard', ['filter' => 'overdue']) }}" class="text-decoration-none">
                @include('dashboard.partials.stat-card', [
                    'label' => 'Overdue Cases',
                    'value' => $stats['overdue_cases'] ?? 0,
                    'icon' => 'bi-exclamation-octagon-fill',
                    'color' => 'danger',
                ])
            </a>
        </div>
        <div class="col-md-6">
            <a href="{{ route('dashboard', ['filter' => 'warning']) }}" class="text-decoration-none">
                @include('dashboard.partials.stat-card', [
                    'label' => 'Warning Cases',
                    'value' => $stats['warning_cases'] ?? 0,
                    'icon' => 'bi-exclamation-triangle-fill',
                    'color' => 'warning',
                ])
            </a>
        </div>
    </div>
@endif
