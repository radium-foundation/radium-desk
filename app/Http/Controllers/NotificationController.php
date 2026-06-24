<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return view('notifications.index', [
            'notifications' => $notifications,
        ]);
    }

    public function show(Request $request, string $notification): RedirectResponse
    {
        $databaseNotification = $request->user()
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        if ($databaseNotification->read_at === null) {
            $databaseNotification->markAsRead();
        }

        $url = $databaseNotification->data['url'] ?? route('dashboard');

        return redirect()->to($url);
    }

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $databaseNotification = $request->user()
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        if ($databaseNotification->read_at === null) {
            $databaseNotification->markAsRead();
        }

        return redirect()
            ->route('notifications.index')
            ->with('status', 'notification-read');
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return redirect()
            ->route('notifications.index')
            ->with('status', 'notifications-read-all');
    }
}
