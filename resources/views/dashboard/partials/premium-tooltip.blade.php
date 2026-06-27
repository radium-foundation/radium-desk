@props(['title' => null, 'sections' => [], 'lines' => [], 'footer' => null, 'compact' => null])

<div @class(['dashboard-premium-tooltip', 'dashboard-premium-tooltip--compact' => $compact !== null])>
    @if($compact)
        <div class="dashboard-premium-tooltip__compact-line">{{ $compact['datetime'] }}</div>
        <div class="dashboard-premium-tooltip__compact-line">
            <span class="{{ $compact['durationClass'] }}">{{ $compact['pendingDuration'] }}</span>
        </div>
    @else
        @if($title)
            <div class="dashboard-premium-tooltip__title">{{ $title }}</div>
        @endif
        @if(count($lines) > 0)
            <div class="dashboard-premium-tooltip__lines">
                @foreach($lines as $line)
                    <div class="dashboard-premium-tooltip__line">{{ $line }}</div>
                @endforeach
            </div>
        @endif
        @foreach($sections as $section)
            <div class="dashboard-premium-tooltip__section">
                <div class="dashboard-premium-tooltip__label">{{ $section['label'] }}</div>
                <div class="dashboard-premium-tooltip__value">{{ $section['value'] }}</div>
            </div>
        @endforeach
        @if($footer)
            <div class="dashboard-premium-tooltip__footer">{{ $footer }}</div>
        @endif
    @endif
</div>
