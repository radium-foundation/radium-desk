@props([
    'widget' => null,
])

@if(is_array($widget))
    <a href="{{ $widget['url'] }}"
       @class([
           'dashboard-kpi-item',
           'dashboard-u-surface-card',
           'dashboard-u-transition',
           'dashboard-u-hover-lift',
           'dashboard-u-focus-ring',
           'dashboard-email-intake-kpi',
           'dashboard-email-intake-kpi--'.$widget['severity'],
           'text-decoration-none',
       ])
       data-email-intake-kpi
       aria-label="Email Intake: {{ number_format($widget['needs_attention']) }} needs attention">
        <div class="dashboard-email-intake-kpi__body">
            <div class="dashboard-email-intake-kpi__title">{{ $widget['title'] }}</div>
            <div class="dashboard-email-intake-kpi__value">{{ number_format($widget['needs_attention']) }}</div>
            <div class="dashboard-email-intake-kpi__subtitle">{{ $widget['subtitle'] }}</div>
        </div>

        <div class="dashboard-email-intake-kpi__hover" role="tooltip" aria-hidden="true">
            <div class="dashboard-email-intake-kpi__hover-title">Needs Attention</div>
            <dl class="dashboard-email-intake-kpi__hover-list">
                @foreach($widget['hover']['needs_attention'] as $row)
                    <div class="dashboard-email-intake-kpi__hover-row">
                        <dt>{{ $row['label'] }}</dt>
                        <dd>{{ number_format($row['count']) }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="dashboard-email-intake-kpi__hover-divider" aria-hidden="true"></div>
            <dl class="dashboard-email-intake-kpi__hover-list">
                @foreach($widget['hover']['ignored'] as $row)
                    <div class="dashboard-email-intake-kpi__hover-row">
                        <dt>{{ $row['label'] }}</dt>
                        <dd>{{ number_format($row['count']) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </a>
@endif
