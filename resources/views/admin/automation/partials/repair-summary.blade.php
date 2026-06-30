@props([
    'statistics',
])

@php
    $items = [
        ['label' => 'Total Repaired', 'value' => $statistics->totalRepaired, 'icon' => 'bi-wrench-adjustable', 'color' => 'success'],
        ['label' => 'Duplicate Conflicts', 'value' => $statistics->duplicateConflicts, 'icon' => 'bi-files', 'color' => 'danger'],
        ['label' => 'Waiting Customer Serial', 'value' => $statistics->waitingCustomerSerial, 'icon' => 'bi-keyboard', 'color' => 'info'],
        ['label' => 'Validation Failures', 'value' => $statistics->validationFailures, 'icon' => 'bi-exclamation-triangle', 'color' => 'warning'],
        ['label' => 'Not Found', 'value' => $statistics->notFound, 'icon' => 'bi-search', 'color' => 'secondary'],
    ];
@endphp

<section class="mb-4" aria-labelledby="repair-summary-heading">
    <h2 id="repair-summary-heading" class="h5 mb-3">Repair Summary</h2>

    <div class="dashboard-kpi-strip dashboard-kpi-strip--admin mb-3" role="region" aria-label="Repair summary counts">
        @foreach($items as $item)
            @include('dashboard.partials.kpi-strip-item', $item)
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-muted small">Last repair run</span>
                <span class="fw-semibold">
                    {{ $statistics->lastRepairRun !== null ? display_app_datetime_seconds($statistics->lastRepairRun) : 'Never' }}
                </span>
            </div>
        </div>
    </div>
</section>
