@props([
    'stats',
])

<div class="row g-2 mb-3">
    @isset($stats['pending_approvals'])
        <div class="col-md-6 col-lg-4">
            <a href="{{ route('approvals.index') }}" class="text-decoration-none">
                @include('dashboard.partials.stat-card', [
                    'label' => 'Pending Approvals',
                    'value' => $stats['pending_approvals'],
                    'icon' => 'bi-check2-square',
                    'color' => 'primary',
                ])
            </a>
        </div>
    @endisset

    @isset($stats['pending_refunds'])
        <div class="col-md-6 col-lg-4">
            <a href="{{ route('refunds.index', ['status' => 'pending']) }}" class="text-decoration-none">
                @include('dashboard.partials.stat-card', [
                    'label' => 'Pending Refunds',
                    'value' => $stats['pending_refunds'],
                    'icon' => 'bi-hourglass-split',
                    'color' => 'warning',
                ])
            </a>
        </div>
    @endisset

    <div class="col-md-6 col-lg-4">
        <a href="{{ route('incidents.index') }}" class="text-decoration-none">
            @include('dashboard.partials.stat-card', [
                'label' => 'Open Service Cases',
                'value' => $stats['open_incidents'],
                'icon' => 'bi-exclamation-triangle',
                'color' => 'danger',
            ])
        </a>
    </div>
</div>
