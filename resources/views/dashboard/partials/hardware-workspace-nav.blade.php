@php
    use App\Enums\HardwareWorkspaceFilter;
    use App\Enums\HardwareWorkspaceScope;

    $scopeCounts = $hardwareWorkspace['scope_counts'] ?? [];
    $filterCounts = $hardwareWorkspace['filter_counts'] ?? [];
    $activeScope = $hardwareWorkspace['scope'] ?? HardwareWorkspaceScope::Active->value;
    $activeFilter = $hardwareWorkspace['filter'] ?? HardwareWorkspaceFilter::All->value;
    $search = $hardwareWorkspace['search'] ?? '';

    $hardwareUrl = static function (array $params = []) use ($search): string {
        return route('dashboard', array_filter([
            'workspace' => 'hardware',
            'hw_scope' => $params['hw_scope'] ?? null,
            'hw_filter' => $params['hw_filter'] ?? null,
            'q' => $search !== '' ? $search : null,
        ]));
    };
@endphp

<div class="dashboard-hardware-nav">
    <div class="dashboard-case-filters dashboard-operation-queues dashboard-hardware-nav__scopes"
         role="tablist"
         aria-label="Hardware workspace">
        @foreach($hardwareWorkspace['scopes'] ?? HardwareWorkspaceScope::cases() as $scope)
            @php
                $scopeCount = $scopeCounts[$scope->value] ?? 0;
                $scopeActive = $activeScope === $scope->value;
            @endphp
            <a href="{{ $hardwareUrl(['hw_scope' => $scope->value, 'hw_filter' => HardwareWorkspaceFilter::All->value]) }}"
               @class([
                   'dashboard-case-filter-chip',
                   'dashboard-case-filter-chip--' . $scope->tone(),
                   'is-active' => $scopeActive,
               ])
               role="tab"
               @if($scopeActive) aria-selected="true" aria-current="page" @else aria-selected="false" @endif>
                <span class="dashboard-case-filter-chip__label">{{ $scope->label() }}</span>
                <span class="dashboard-case-filter-chip__count"
                      data-hardware-scope-count="{{ $scope->value }}">({{ $scopeCount }})</span>
            </a>
        @endforeach
    </div>

    @if($activeScope === HardwareWorkspaceScope::Active->value)
        <div class="dashboard-case-filters dashboard-operation-queues dashboard-hardware-nav__filters"
             role="tablist"
             aria-label="Hardware status filters">
            @foreach($hardwareWorkspace['filters'] ?? HardwareWorkspaceFilter::cases() as $filter)
                @php
                    $filterCount = $filterCounts[$filter->value] ?? 0;
                    $filterActive = $activeFilter === $filter->value;
                @endphp
                <a href="{{ $hardwareUrl(['hw_scope' => HardwareWorkspaceScope::Active->value, 'hw_filter' => $filter->value]) }}"
                   @class([
                       'dashboard-case-filter-chip',
                       'dashboard-case-filter-chip--' . $filter->tone(),
                       'dashboard-case-filter-chip--subtle',
                       'is-active' => $filterActive,
                   ])
                   role="tab"
                   @if($filterActive) aria-selected="true" aria-current="page" @else aria-selected="false" @endif>
                    <span class="dashboard-case-filter-chip__label">{{ $filter->label() }}</span>
                    <span class="dashboard-case-filter-chip__count"
                          data-hardware-filter-count="{{ $filter->value }}">({{ $filterCount }})</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
