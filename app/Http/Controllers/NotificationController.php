<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = $user->notifications()->orderByDesc('created_at')->paginate(15);

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $this->ownedNotification($request, $notification);
        $item->markAsRead();

        $url = $item->data['url'] ?? null;
        if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return redirect($url);
        }

        return redirect()->route($this->notificationRoute($request));
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route($this->notificationRoute($request))
            ->with('status', 'All notifications marked as read.');
    }

    private function ownedNotification(Request $request, string $id): DatabaseNotification
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        return $notification;
    }

    private function notificationRoute(Request $request): string
    {
        return match ($request->user()->role) {
            'superadmin' => 'admin.notifications',
            default => $request->user()->role . '.notifications',
        };
    }
}
