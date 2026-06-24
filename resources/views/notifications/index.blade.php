@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Notifications</h1>
            <p class="text-muted small mb-0">Your in-app updates for assigned work and service case activity.</p>
        </div>
        @if(auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-check2-all me-1"></i> Mark all as read
                </button>
            </form>
        @endif
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($notifications->isEmpty())
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-bell fs-3 d-block mb-2"></i>
                    No notifications yet.
                </div>
            @else
                <div class="list-group list-group-flush">
                    @foreach($notifications as $notification)
                        @php
                            $isUnread = $notification->read_at === null;
                        @endphp
                        <div @class([
                            'list-group-item list-group-item-action notification-list-item',
                            'notification-list-item--unread' => $isUnread,
                        ])>
                            <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-start gap-2">
                                        @if($isUnread)
                                            <span class="notification-unread-dot mt-2" aria-hidden="true"></span>
                                        @endif
                                        <div>
                                            <div class="fw-semibold">{{ $notification->data['title'] ?? 'Notification' }}</div>
                                            <div class="small text-muted">{{ $notification->data['message'] ?? '' }}</div>
                                            <div class="small text-muted mt-1">{{ $notification->created_at?->diffForHumans() }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 align-items-start">
                                    <a href="{{ route('notifications.show', $notification->id) }}" class="btn btn-sm btn-primary">
                                        Open
                                    </a>
                                    @if($isUnread)
                                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                Mark read
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        @if($notifications->hasPages())
            <div class="card-footer bg-white">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
@endsection
