@props(['title' => null, 'sections' => [], 'lines' => [], 'footer' => null])

<div class="dashboard-premium-tooltip">
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
</div>
