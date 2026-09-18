@php
    use App\Enums\HardwareWorkspaceFilter;
    use App\Enums\HardwareWorkspaceScope;

    $scopeCounts = $hardwareWorkspace['scope_counts'] ?? [];
    $filterCounts = $hardwareWorkspace['filter_counts'] ?? [];
    $activeScope = $hardwareWorkspace['scope'] ?? HardwareWorkspaceScope::Active->value;
    $activeFilter = $hardwareWorkspace['filter'] ?? HardwareWorkspaceFilter::NeedsAction->value;
    $search = $hardwareWorkspace['search'] ?? '';
    $activeFilterEnum = HardwareWorkspaceFilter::tryFrom($activeFilter) ?? HardwareWorkspaceFilter::NeedsAction;
    $readyForPickupTopOpen = $activeScope === HardwareWorkspaceScope::Active->value
        && $activeFilterEnum->isReadyForPickupWorkspace();
    $needsActionOpen = $activeScope === HardwareWorkspaceScope::Active->value && $activeFilterEnum->isNeedsAction();
    $shippingTopOpen = $activeScope === HardwareWorkspaceScope::Active->value && $activeFilterEnum->isShippingTopTab();
    $shippingSubNavOpen = $activeScope === HardwareWorkspaceScope::Active->value && $activeFilterEnum->showsShippingLifecycleSubNav();
    $completedOpen = $activeScope === HardwareWorkspaceScope::Shipped->value
        || in_array($activeFilterEnum, [HardwareWorkspaceFilter::Completed, HardwareWorkspaceFilter::Delivered], true);

    $hardwareUrl = static function (array $params = []) use ($search): string {
        return route('dashboard', array_filter([
            'workspace' => 'hardware',
            'hw_scope' => $params['hw_scope'] ?? HardwareWorkspaceScope::Active->value,
            'hw_filter' => $params['hw_filter'] ?? null,
            'q' => $search !== '' ? $search : null,
        ], static fn ($value) => $value !== null && $value !== ''));
    };
@endphp

<div class="dashboard-hardware-nav">
    <div class="dashboard-case-filters dashboard-operation-queues dashboard-hardware-nav__groups"
         role="tablist"
         aria-label="Hardware workspace">
        <a href="{{ $hardwareUrl(['hw_filter' => HardwareWorkspaceFilter::NeedsAction->value]) }}"
           @class(['dashboard-case-filter-chip', 'dashboard-case-filter-chip--danger', 'is-active' => $needsActionOpen])
           role="tab"
           @if($needsActionOpen) aria-selected="true" aria-current="page" @else aria-selected="false" @endif>
            <span class="dashboard-case-filter-chip__label">Needs Action</span>
            <span class="dashboard-case-filter-chip__count" data-hardware-filter-count="needs_action">({{ $filterCounts['needs_action'] ?? 0 }})</span>
        </a>
        <a href="{{ $hardwareUrl(['hw_filter' => HardwareWorkspaceFilter::ReadyForPickup->value]) }}"
           @class(['dashboard-case-filter-chip', 'dashboard-case-filter-chip--warning', 'is-active' => $readyForPickupTopOpen])
           role="tab"
           @if($readyForPickupTopOpen) aria-selected="true" aria-current="page" @else aria-selected="false" @endif>
            <span class="dashboard-case-filter-chip__label">Ready for Pickup</span>
            <span class="dashboard-case-filter-chip__count" data-hardware-filter-count="ready_for_pickup">({{ $filterCounts['ready_for_pickup'] ?? 0 }})</span>
        </a>
        <a href="{{ $hardwareUrl(['hw_filter' => HardwareWorkspaceFilter::Shipping->value]) }}"
           @class(['dashboard-case-filter-chip', 'dashboard-case-filter-chip--warning', 'is-active' => $shippingTopOpen])
           role="tab"
           @if($shippingTopOpen) aria-selected="true" aria-current="page" @else aria-selected="false" @endif>
            <span class="dashboard-case-filter-chip__label">Shipping</span>
            <span class="dashboard-case-filter-chip__count" data-hardware-filter-count="shipping">({{ $filterCounts['shipping'] ?? 0 }})</span>
        </a>
        <a href="{{ $hardwareUrl(['hw_scope' => HardwareWorkspaceScope::Shipped->value, 'hw_filter' => HardwareWorkspaceFilter::Completed->value]) }}"
           @class(['dashboard-case-filter-chip', 'dashboard-case-filter-chip--primary', 'is-active' => $completedOpen])
           role="tab"
           @if($completedOpen) aria-selected="true" aria-current="page" @else aria-selected="false" @endif>
            <span class="dashboard-case-filter-chip__label">Completed</span>
            <span class="dashboard-case-filter-chip__count" data-hardware-filter-count="completed">({{ $filterCounts['completed'] ?? $scopeCounts['shipped'] ?? 0 }})</span>
        </a>
        <a href="{{ $hardwareUrl(['hw_filter' => HardwareWorkspaceFilter::All->value]) }}"
           @class(['dashboard-case-filter-chip', 'dashboard-case-filter-chip--secondary', 'dashboard-case-filter-chip--subtle', 'is-active' => $activeFilter === HardwareWorkspaceFilter::All->value && $activeScope === HardwareWorkspaceScope::Active->value])>
            <span class="dashboard-case-filter-chip__label">All</span>
            <span class="dashboard-case-filter-chip__count" data-hardware-scope-count="active">({{ $scopeCounts['active'] ?? 0 }})</span>
        </a>
    </div>

    @if($needsActionOpen)
        <div class="dashboard-case-filters dashboard-operation-queues dashboard-hardware-nav__filters"
             role="tablist"
             aria-label="Needs Action queues">
            @foreach($hardwareWorkspace['needs_action_filters'] ?? HardwareWorkspaceFilter::needsActionFilters() as $filter)
                @php
                    $filterActive = $activeFilter === $filter->value;
                @endphp
                <a href="{{ $hardwareUrl(['hw_filter' => $filter->value]) }}"
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
                          data-hardware-filter-count="{{ $filter->value }}">({{ $filterCounts[$filter->value] ?? 0 }})</span>
                </a>
            @endforeach
        </div>
    @endif

    @if($shippingSubNavOpen)
        <div class="dashboard-case-filters dashboard-operation-queues dashboard-hardware-nav__filters"
             role="tablist"
             aria-label="Shipping queues">
            @foreach($hardwareWorkspace['shipping_filters'] ?? HardwareWorkspaceFilter::shippingFilters() as $filter)
                @php
                    $filterActive = $activeFilter === $filter->value
                        && ! ($readyForPickupTopOpen && $filter === HardwareWorkspaceFilter::ReadyForPickup);
                @endphp
                <a href="{{ $hardwareUrl(['hw_filter' => $filter->value]) }}"
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
                          data-hardware-filter-count="{{ $filter->value }}">({{ $filterCounts[$filter->value] ?? 0 }})</span>
                </a>
            @endforeach
        </div>
    @endif

    @if($completedOpen)
        <div class="dashboard-case-filters dashboard-operation-queues dashboard-hardware-nav__filters"
             role="tablist"
             aria-label="Completed queues">
            <a href="{{ $hardwareUrl(['hw_scope' => HardwareWorkspaceScope::Shipped->value, 'hw_filter' => HardwareWorkspaceFilter::Delivered->value]) }}"
               @class([
                   'dashboard-case-filter-chip',
                   'dashboard-case-filter-chip--primary',
                   'dashboard-case-filter-chip--subtle',
                   'is-active' => $activeFilterEnum === HardwareWorkspaceFilter::Delivered || $activeFilterEnum === HardwareWorkspaceFilter::Completed,
               ])>
                <span class="dashboard-case-filter-chip__label">Delivered</span>
                <span class="dashboard-case-filter-chip__count"
                      data-hardware-filter-count="delivered">({{ $filterCounts['delivered'] ?? $filterCounts['completed'] ?? 0 }})</span>
            </a>
        </div>
    @endif
</div>
