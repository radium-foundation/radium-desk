@php
    $communicationActions = collect($communicationActions ?? [])
        ->filter(fn (array $action): bool => (bool) ($action['eligible'] ?? false))
        ->values();
@endphp

@if($communicationActions->isNotEmpty())
    <section class="customer-360-section"
             data-customer-360-section="communication-actions"
             aria-labelledby="customer-360-communication-actions-heading">
        <h3 class="customer-360-section-title" id="customer-360-communication-actions-heading">
            Communication Actions
        </h3>

        <ul class="list-unstyled customer-360-communication-actions mb-0">
            @foreach($communicationActions as $action)
                <li class="customer-360-communication-action-item">
                    <button type="button"
                            class="customer-360-communication-action-button"
                            data-workspace-trigger="communication-action"
                            data-workspace-communication-action-key="{{ $action['key'] }}"
                            data-workspace-incident-id="{{ $incident->id }}"
                            data-workspace-context="customer">
                        <span class="customer-360-communication-action-icon" aria-hidden="true">
                            <i class="bi {{ $action['icon'] }}"></i>
                        </span>
                        <span class="customer-360-communication-action-copy">
                            <span class="customer-360-communication-action-name">{{ $action['name'] }}</span>
                            <span class="customer-360-communication-action-description">{{ $action['description'] }}</span>
                            <span class="customer-360-communication-action-channels">
                                @foreach($action['channels'] as $channel)
                                    <span class="badge rounded-pill text-bg-light">{{ $channel['label'] }}</span>
                                @endforeach
                            </span>
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>
@endif
