<section class="customer-360-section"
         data-customer-360-section="communication-actions"
         aria-labelledby="customer-360-communication-actions-heading">
    <h3 class="customer-360-section-title" id="customer-360-communication-actions-heading">Communication Actions</h3>

    @if(($communicationActionStatuses ?? []) === [])
        <p class="customer-360-empty-text mb-0">No communication actions configured.</p>
    @else
        <div class="customer-360-communication-actions" role="list" aria-label="Communication action status">
            @foreach($communicationActionStatuses as $action)
                <article class="customer-360-communication-action-card"
                         role="listitem"
                         data-communication-action-status="{{ $action['status'] }}"
                         data-communication-action-key="{{ $action['key'] }}">
                    <div class="customer-360-communication-action-card-header">
                        <span class="customer-360-communication-action-card-icon" aria-hidden="true">
                            {!! \App\Support\Customer360\Customer360OverflowMenuLucideIcon::render($action['icon']) !!}
                        </span>
                        <h4 class="customer-360-communication-action-card-title">{{ $action['name'] }}</h4>
                    </div>

                    <div class="customer-360-communication-action-card-status">
                        <x-c360.status-banner
                            :variant="$action['status_variant']"
                            :icon="$action['status_icon']"
                            class="c360-status-banner--compact">
                            {{ $action['status_label'] }}
                        </x-c360.status-banner>

                        @if($action['show_already_sent'] && filled($action['already_sent_label'] ?? null))
                            <span class="customer-360-communication-action-already-sent">
                                {{ $action['already_sent_label'] }}
                            </span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
