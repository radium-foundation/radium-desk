@php
    use App\Support\Dashboard\TeamActivityPresenceLegend;

    $entries = TeamActivityPresenceLegend::entries();
@endphp

<span class="team-activity-grid-header__title-row">
    <span class="team-activity-grid-header__title">Presence</span>
    <button type="button"
            class="team-activity-presence-legend-trigger"
            data-bs-toggle="tooltip"
            data-dashboard-tooltip
            data-bs-placement="bottom"
            data-bs-container="body"
            data-bs-boundary="viewport"
            data-bs-custom-class="dashboard-premium-tooltip-wrapper team-activity-presence-legend-tooltip"
            aria-label="Presence status legend">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
    </button>
</span>
<template class="dashboard-tooltip-template">
    <div class="dashboard-premium-tooltip team-activity-presence-legend">
        <div class="dashboard-premium-tooltip__title">Presence legend</div>
        <div class="team-activity-presence-legend__list" role="list">
            @foreach($entries as $entry)
                <div @class([
                    'team-activity-presence-legend__row',
                    'team-activity-presence-legend__row--future' => ($entry['future'] ?? false),
                ]) role="listitem">
                    <span class="team-activity-presence-legend__abbr">{{ $entry['abbr'] }}</span>
                    <span class="team-activity-presence-legend__label">
                        {{ $entry['label'] }}@if($entry['future'] ?? false) <span class="team-activity-presence-legend__future">(future)</span>@endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>
</template>
