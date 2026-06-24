<div class="dropdown me-2">
    <button
        type="button"
        class="btn btn-light border position-relative notification-bell-btn"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label="Notifications"
    >
        <span aria-hidden="true">🔔</span>
        @if($notificationUnreadBadge)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-count-badge">
                {{ $notificationUnreadBadge }}
            </span>
        @endif
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow notification-dropdown">
        <div class="dropdown-header fw-semibold">Notifications</div>

        @forelse($latestNotifications as $notification)
            @php
                $isUnread = $notification->read_at === null;
            @endphp
            <a href="{{ route('notifications.show', $notification->id) }}"
               @class([
                   'dropdown-item notification-dropdown-item py-2',
                   'notification-dropdown-item--unread' => $isUnread,
               ])>
                <div class="fw-semibold small">{{ $notification->data['title'] ?? 'Notification' }}</div>
                <div class="text-muted small">{{ $notification->data['message'] ?? '' }}</div>
                <div class="text-muted small">{{ $notification->created_at?->diffForHumans() }}</div>
            </a>
        @empty
            <div class="dropdown-item-text text-muted small py-3 text-center">
                No notifications yet.
            </div>
        @endforelse

        <div class="dropdown-divider mb-0"></div>
        <a href="{{ route('notifications.index') }}" class="dropdown-item text-center small fw-semibold py-2">
            View All Notifications
        </a>
    </div>
</div>
