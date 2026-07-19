@props([
    'recentActivity',
])

<div class="dashboard-activity-feed">
    <div class="dashboard-activity-feed-header">
        <h2 class="dashboard-section-title dashboard-section-title--secondary mb-0">Recent Activity</h2>
        <p class="dashboard-activity-feed-subtitle">Latest operator and automation events across the desk.</p>
    </div>

    <ul class="dashboard-activity-timeline list-unstyled mb-0" role="list">
        @foreach($recentActivity as $item)
            <x-dashboard.recent-activity-item :item="$item" />
        @endforeach
    </ul>
</div>
