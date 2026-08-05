@props([
    'counters' => [],
])

@if($counters !== [])
    <div class="dashboard-email-intake-counters"
         role="group"
         aria-label="Email intake queues"
         data-email-intake-counters>
        @foreach($counters as $counter)
            <a href="{{ $counter['url'] }}"
               class="dashboard-email-intake-counter dashboard-u-focus-ring"
               title="{{ $counter['tooltip'] }}"
               aria-label="{{ $counter['label'] }}: {{ number_format($counter['count']) }}">
                <span class="dashboard-email-intake-counter__symbol" aria-hidden="true">{{ $counter['emoji'] }}</span>
                @if($counter['uses_superscript'])
                    <sup class="dashboard-email-intake-counter__sup">{{ number_format($counter['count']) }}</sup>
                @else
                    <span class="dashboard-email-intake-counter__count">{{ number_format($counter['count']) }}</span>
                @endif
            </a>
        @endforeach
    </div>
@endif
