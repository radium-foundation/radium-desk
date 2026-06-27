@props(['title' => null, 'sections' => []])

<div class="dashboard-premium-tooltip">
    @if($title)
        <div class="dashboard-premium-tooltip__title">{{ $title }}</div>
    @endif
    @foreach($sections as $section)
        <div class="dashboard-premium-tooltip__section">
            <div class="dashboard-premium-tooltip__label">{{ $section['label'] }}</div>
            <div class="dashboard-premium-tooltip__value">{{ $section['value'] }}</div>
        </div>
    @endforeach
</div>
