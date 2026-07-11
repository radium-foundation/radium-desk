@props([
    'icon' => 'bi-inbox',
    'title',
    'description' => null,
    'actionLabel' => null,
    'actionHref' => null,
    'actionTrigger' => null,
    'actionIcon' => null,
    'incidentId' => null,
    'compact' => false,
])

<div {{ $attributes->merge(['class' => 'c360-empty-state' . ($compact ? ' c360-empty-state--compact' : '')]) }}
     role="status">
    <div class="c360-empty-state-icon" aria-hidden="true">
        <i class="bi {{ $icon }}"></i>
    </div>
    <p class="c360-empty-state-title mb-0">{{ $title }}</p>
    @if(filled($description))
        <p class="c360-empty-state-description mb-0">{{ $description }}</p>
    @endif
    @if(filled($actionLabel))
        @if(filled($actionTrigger) && filled($incidentId))
            <button type="button"
                    class="c360-empty-state-action"
                    data-workspace-trigger="{{ $actionTrigger }}"
                    data-workspace-incident-id="{{ $incidentId }}"
                    data-workspace-context="customer">
                @if(filled($actionIcon))
                    <i class="bi {{ $actionIcon }}" aria-hidden="true"></i>
                @endif
                {{ $actionLabel }}
            </button>
        @elseif(filled($actionHref))
            <a href="{{ $actionHref }}" class="c360-empty-state-action">
                @if(filled($actionIcon))
                    <i class="bi {{ $actionIcon }}" aria-hidden="true"></i>
                @endif
                {{ $actionLabel }}
            </a>
        @else
            <button type="button" class="c360-empty-state-action" {{ $attributes->whereStartsWith('data-') }}>
                @if(filled($actionIcon))
                    <i class="bi {{ $actionIcon }}" aria-hidden="true"></i>
                @endif
                {{ $actionLabel }}
            </button>
        @endif
    @endif
    {{ $slot }}
</div>
