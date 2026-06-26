@props([
    'recentActivity',
])

<div class="dashboard-activity-feed mb-2">
    <h2 class="dashboard-section-title h6 mb-1">Recent Activity</h2>
    <ul class="list-group list-group-flush dashboard-activity-list border rounded">
        @foreach($recentActivity as $log)
            <li class="list-group-item dashboard-activity-item py-1 px-2">
                <div class="d-flex flex-wrap align-items-baseline gap-1">
                    <span class="dashboard-activity-time text-muted">{{ display_app_datetime($log->created_at) }}</span>
                    <span class="badge text-bg-light text-dark border text-capitalize dashboard-activity-event">
                        {{ str_replace('_', ' ', $log->event) }}
                    </span>
                    <span class="dashboard-activity-reference small">
                        {{ class_basename($log->auditable_type) }}
                        @if($log->auditable_id)
                            <span class="text-muted">#{{ $log->auditable_id }}</span>
                        @endif
                    </span>
                    <span class="dashboard-activity-user text-muted small ms-auto">
                        {{ $log->user?->firstName() ?: 'System' }}
                    </span>
                </div>
            </li>
        @endforeach
    </ul>
</div>
