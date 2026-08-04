@props([
    'alert',
    'operationsCardCount' => null,
])

@php
    /** @var \App\Data\Platform\PlatformAlert $alert */
    $isOperationsSnapshot = $alert->source === 'executive_snapshot'
        || $alert->groupKey === 'executive_snapshot';

    $operationsStatus = null;
    $affectedCards = $operationsCardCount;

    if ($isOperationsSnapshot) {
        foreach ($alert->related as $related) {
            if (($related['title'] ?? '') === 'operations_status') {
                $operationsStatus = (string) ($related['summary'] ?? '');
            }
            if (($related['title'] ?? '') === 'affected_kpi_cards' && $affectedCards === null) {
                $affectedCards = (int) ($related['summary'] ?? 0);
            }
        }

        if ($operationsStatus === null || $operationsStatus === '') {
            $operationsStatus = str_starts_with($alert->status, 'Operations ')
                ? substr($alert->status, strlen('Operations '))
                : $alert->status;
        }
    }
@endphp

<div class="platform-critical-alert-detail border rounded-3 p-3 bg-body-tertiary">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="badge text-bg-{{ $alert->severity->badgeClass() }}">{{ $alert->severity->label() }}</span>
        <strong>{{ $alert->title }}</strong>
    </div>

    @if($isOperationsSnapshot)
        <p class="small mb-1">Operations status: {{ $operationsStatus }}</p>
        <p class="small mb-2">Affected KPI cards: {{ $affectedCards ?? 0 }}</p>
    @else
        <p class="small mb-2">{{ $alert->summary }}</p>
    @endif

    <div class="text-muted small mb-3">
        Source: <code>{{ $alert->source }}</code>
        @if($alert->lastUpdated)
            · Last Updated {{ \App\Support\AppDateFormatter::format($alert->lastUpdated, 'g:i A') }}
        @endif
    </div>

    @if(! $isOperationsSnapshot && $alert->related !== [])
        <h3 class="h6">Related issues</h3>
        <ul class="small mb-0">
            @foreach($alert->related as $related)
                <li>
                    <strong>{{ $related['title'] ?? 'Issue' }}</strong>
                    — {{ $related['summary'] ?? '' }}
                    @if(! empty($related['severity']))
                        <span class="text-muted">({{ $related['severity'] }})</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
