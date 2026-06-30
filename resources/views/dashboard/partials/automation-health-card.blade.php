@props([
    'automationHealth' => [],
])

@php
    $rows = [
        ['label' => 'Pending', 'key' => 'automation_pending'],
        ['label' => 'Waiting RB', 'key' => 'radiumbox_pending'],
        ['label' => 'Validation', 'key' => 'validation_failed'],
        ['label' => 'Unassigned', 'key' => 'unassigned'],
        ['label' => 'Repair Needed', 'key' => 'repair_needed'],
    ];
@endphp

<div class="dashboard-automation-health card border-0 shadow-sm mb-1" role="region" aria-label="Automation Health">
    <div class="card-body py-2 px-3">
        <h2 class="h6 mb-2">Automation Health</h2>
        <dl class="dashboard-automation-health-list mb-0">
            @foreach($rows as $row)
                <div class="dashboard-automation-health-row d-flex justify-content-between gap-3">
                    <dt class="text-muted small mb-0">{{ $row['label'] }}</dt>
                    <dd class="small mb-0 fw-semibold">{{ $automationHealth[$row['key']] ?? 0 }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>
