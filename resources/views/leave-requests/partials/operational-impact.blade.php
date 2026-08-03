@php
    /** @var \App\Data\Operations\LeaveOperationalImpact $impact */
    $severityClass = [
        'none' => 'text-bg-light border',
        'low' => 'text-bg-success',
        'medium' => 'text-bg-warning',
        'high' => 'text-bg-danger',
    ];
@endphp

<section class="mb-4" aria-label="Operational impact analysis">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between gap-2">
            <div>
                <h2 class="h6 mb-1">Operational Impact Analysis</h2>
                <p class="text-muted small mb-0">
                    Live workload for {{ $impact->employeeName }} — read only. Approval is not blocked.
                </p>
            </div>
            <span @class([
                'badge align-self-start',
                'text-bg-warning' => $impact->hasWorkload,
                'text-bg-success' => ! $impact->hasWorkload,
            ])>
                {{ $impact->hasWorkload ? 'Workload present' : 'No workload' }}
            </span>
        </div>

        <div class="card-body">
            <div @class([
                'alert small',
                'alert-warning' => $impact->hasWorkload,
                'alert-success' => ! $impact->hasWorkload,
            ])>
                {{ $impact->warningMessage }}
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Section</th>
                            <th class="text-end">Count</th>
                            <th>Severity</th>
                            <th class="text-end">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($impact->sections as $section)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $section['label'] }}</div>
                                    @if(! empty($section['detail']))
                                        <div class="small text-muted">{{ $section['detail'] }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <span class="fw-semibold">{{ $section['display'] }}</span>
                                </td>
                                <td>
                                    <span @class([
                                        'badge',
                                        $severityClass[$section['severity']] ?? 'text-bg-light border',
                                    ])>
                                        {{ $section['severity_label'] }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    @if(! empty($section['view_url']))
                                        <a href="{{ $section['view_url'] }}" class="btn btn-sm btn-outline-primary">View</a>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
