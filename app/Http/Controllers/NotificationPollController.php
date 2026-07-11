<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPollController extends Controller
{
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        $unreadCount = $user->unreadNotifications()->count();

        $badge = match (true) {
            $unreadCount <= 0 => null,
            $unreadCount > 99 => '99+',
            default => (string) $unreadCount,
        };

        $latestNotifications = $user->notifications()->latest()->limit(10)->get();

        $since = $request->query('since');
        $newNotifications = $latestNotifications
            ->filter(function ($notification) use ($since) {
                if ($notification->read_at !== null) {
                    return false;
                }

                if ($since === null || $since === '') {
                    return false;
                }

                return $notification->created_at?->toIso8601String() > $since;
            })
            ->take(5)
            ->map(function ($notification) {
                $item = [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'Notification',
                    'message' => $notification->data['message'] ?? '',
                    'url' => $notification->data['url'] ?? route('notifications.index'),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ];

                if (isset($notification->data['interaction'])) {
                    $item['interaction'] = $notification->data['interaction'];
                }

                return $item;
            })
            ->values();

        return response()->json([
            'unread_count' => $unreadCount,
            'badge' => $badge,
            'bell_html' => view('layouts.partials.notification-bell', [
                'notificationUnreadCount' => $unreadCount,
                'notificationUnreadBadge' => $badge,
                'latestNotifications' => $latestNotifications,
            ])->render(),
            'new_notifications' => $newNotifications,
        ]);
    }
}
