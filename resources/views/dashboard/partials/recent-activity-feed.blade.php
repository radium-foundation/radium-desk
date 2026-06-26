@props([
    'recentActivity',
])

<div class="dashboard-activity-feed">
    <h2 class="dashboard-section-title dashboard-section-title--secondary mb-0">Recent Activity</h2>
    <ul class="dashboard-activity-timeline list-unstyled mb-0">
        @foreach($recentActivity as $log)
            <li class="dashboard-activity-timeline-item">
                <span class="dashboard-activity-timeline-marker" aria-hidden="true"></span>
                <div class="dashboard-activity-timeline-body">
                    <span class="dashboard-activity-time">{{ display_app_datetime($log->created_at) }}</span>
                    <span class="badge dashboard-badge-compact text-bg-light text-dark border text-capitalize">
                        {{ str_replace('_', ' ', $log->event) }}
                    </span>
                    <span class="dashboard-activity-reference">
                        {{ class_basename($log->auditable_type) }}
                        @if($log->auditable_id)
                            <span class="text-muted">#{{ $log->auditable_id }}</span>
                        @endif
                    </span>
                    <span class="dashboard-activity-user text-muted">
                        {{ $log->user?->firstName() ?: 'System' }}
                    </span>
                </div>
            </li>
        @endforeach
    </ul>
</div>
