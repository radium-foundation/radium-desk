@props([
    'variant' => 'filtered',
    'colspan' => 12,
    'rowId' => 'dashboard-service-cases-empty-row',
    'showSearchAgain' => false,
    'clearAction' => 'quick-filter',
])

@php
    $isCaughtUp = $variant === 'caught-up';
    $icon = $isCaughtUp ? 'bi-inbox' : 'bi-search';
    $title = $isCaughtUp ? 'All caught up!' : 'No service cases found';
    $subtitle = $isCaughtUp
        ? 'No service cases require attention right now.'
        : 'Try adjusting your search or filters.';
    $clearAttribute = $clearAction === 'search'
        ? 'data-dashboard-search-clear'
        : 'data-dashboard-quick-filter-clear';
@endphp

<tr id="{{ $rowId }}" class="dashboard-service-cases-empty-row">
    <td colspan="{{ $colspan }}" class="dashboard-service-cases-empty-cell">
        <div class="dashboard-service-cases-empty-state" role="status">
            <div class="dashboard-service-cases-empty-state__icon" aria-hidden="true">
                <i class="bi {{ $icon }}"></i>
            </div>
            <h3 class="dashboard-service-cases-empty-state__title">{{ $title }}</h3>
            <p class="dashboard-service-cases-empty-state__subtitle">{{ $subtitle }}</p>
            @unless($isCaughtUp)
                <div class="dashboard-service-cases-empty-state__actions">
                    <button type="button"
                            class="btn btn-sm btn-primary dashboard-service-cases-empty-state__action"
                            {{ $clearAttribute }}>
                        Clear Filters
                    </button>
                    @if($showSearchAgain)
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary dashboard-service-cases-empty-state__action"
                                data-dashboard-empty-search-again>
                            Search Again
                        </button>
                    @endif
                </div>
            @endunless
        </div>
    </td>
</tr>
