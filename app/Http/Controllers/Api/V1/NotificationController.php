<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * This person's notifications — the web's bell and Notifications page:
 * lab results ready, low stock, appointment reminders.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $page = ($request->boolean('unread') ? $user->unreadNotifications() : $user->notifications())
            ->paginate(min(max((int) $request->query('per_page', 30), 1), 100));

        $rows = [];
        foreach ($page->items() as $n) {
            $data = (array) $n->data;
            $rows[] = [
                'id' => $n->id,
                'type' => $data['type'] ?? class_basename($n->type),
                'title' => $data['title'] ?? 'Notification',
                'message' => $data['message'] ?? null,
                'data' => $data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ];
        }

        return ApiResponse::paginated($page, $rows, ['unread' => $user->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return ApiResponse::success(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::success(['unread' => 0]);
    }
}
