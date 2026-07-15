<section class="customer-360-section"
         data-customer-360-section="communication-actions"
         aria-labelledby="customer-360-communication-actions-heading">
    <h3 class="customer-360-section-title" id="customer-360-communication-actions-heading">Communication Actions</h3>

    @if(($communicationActionStatuses ?? []) === [])
        <p class="customer-360-empty-text mb-0">No communication actions configured.</p>
    @else
        <div class="c360-communication-actions-list" role="list" aria-label="Communication actions">
            @foreach($communicationActionStatuses as $action)
                @php
                    $rowClasses = [
                        'c360-communication-action-row',
                        $action['clickable'] ? 'c360-communication-action-row--active' : 'c360-communication-action-row--disabled',
                    ];
                @endphp

                @if($action['clickable'])
                    <button type="button"
                            @class($rowClasses)
                            role="listitem"
                            data-communication-action-status="{{ $action['status'] }}"
                            data-communication-action-key="{{ $action['key'] }}"
                            data-workspace-trigger="communication-action"
                            data-workspace-communication-action-key="{{ $action['key'] }}"
                            data-workspace-incident-id="{{ $incident->id }}"
                            data-workspace-context="customer">
                        <span class="c360-communication-action-icon-wrap" aria-hidden="true">
                            <i class="bi {{ $action['icon_class'] }}"></i>
                        </span>
                        <span class="c360-communication-action-content">
                            <span class="c360-communication-action-title">{{ $action['display_name'] }}</span>
                            @if(filled($action['helper_text'] ?? null))
                                <span class="c360-communication-action-helper">{{ $action['helper_text'] }}</span>
                            @endif
                        </span>
                        @if($action['show_chevron'])
                            <i class="bi bi-chevron-right c360-communication-action-chevron" aria-hidden="true"></i>
                        @endif
                    </button>
                @else
                    <div @class($rowClasses)
                         role="listitem"
                         aria-disabled="true"
                         data-communication-action-status="{{ $action['status'] }}"
                         data-communication-action-key="{{ $action['key'] }}">
                        <span class="c360-communication-action-icon-wrap" aria-hidden="true">
                            <i class="bi {{ $action['icon_class'] }}"></i>
                        </span>
                        <span class="c360-communication-action-content">
                            <span class="c360-communication-action-title">{{ $action['display_name'] }}</span>
                            @if(filled($action['helper_text'] ?? null))
                                <span class="c360-communication-action-helper">{{ $action['helper_text'] }}</span>
                            @endif
                        </span>
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</section>
