@props([
    'streams',
])

<div class="dashboard-activity-feed" data-dashboard-activity-feed>
    <h2 class="dashboard-section-title dashboard-section-title--secondary mb-0">Recent Activity</h2>

    @foreach($streams->sections() as $section)
        <section class="dashboard-activity-stream"
                 data-dashboard-activity-stream="{{ $section['key'] }}"
                 data-collapsed-default="{{ $section['collapsed_default'] ? '1' : '0' }}">
            <button type="button"
                    class="dashboard-activity-stream-toggle"
                    data-dashboard-activity-stream-toggle
                    aria-expanded="{{ $section['collapsed_default'] ? 'false' : 'true' }}">
                <span class="dashboard-activity-stream-chevron" aria-hidden="true"></span>
                <span class="dashboard-activity-stream-label">{{ $section['label'] }}</span>
                <span class="dashboard-activity-stream-count">({{ $section['items']->count() }})</span>
            </button>

            <ul class="dashboard-activity-timeline list-unstyled mb-0"
                data-dashboard-activity-stream-panel
                @if($section['collapsed_default']) hidden @endif
                role="list">
                @foreach($section['items'] as $item)
                    <x-dashboard.recent-activity-item :item="$item" />
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
