<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * This person's notifications — the web's bell and Notifications page, with
 * the standard fields (App\Notifications\Concerns\AppNotification) top-level.
 * docs/api/notify.md.
 */
class NotificationController extends Controller
{
    /**
     * Notifications stored before the standard payload have no category; what
     * their `type` implies, so a filter and the app still treat them well.
     */
    private const LEGACY = [
        'lab_result_ready' => ['clinical', 'urgent', 'admin.lab-orders.index', 'lab_order', 'lab_order_uuid'],
        'low_stock' => ['stock', 'normal', 'admin.stock.alerts', 'stock_item', 'stock_item_uuid'],
        'appointment_reminder' => ['schedule', 'normal', 'admin.appointments.index', 'appointment', 'appointment_uuid'],
        'trial_ending' => ['account', 'normal', 'admin.subscription.index', 'subscription', null],
        'subscription_activated' => ['billing', 'low', 'admin.subscription.index', 'subscription', null],
        'welcome_trial' => ['account', 'low', 'admin.onboarding', 'onboarding', null],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'since' => ['nullable', 'date'],
            'category' => ['nullable', 'in:'.implode(',', NotificationPreference::CATEGORIES)],
            'priority' => ['nullable', 'in:'.implode(',', NotificationPreference::PRIORITIES)],
        ]);

        $query = $request->boolean('unread') ? $user->unreadNotifications() : $user->notifications();

        if ($request->filled('since')) {
            $query->where('created_at', '>', Carbon::parse((string) $request->query('since')));
        }
        if ($request->filled('category')) {
            $category = (string) $request->query('category');
            $legacy = array_keys(array_filter(self::LEGACY, fn ($l) => $l[0] === $category));
            $query->where(fn ($q) => $q->where('data->category', $category)
                ->orWhere(fn ($q2) => $q2->whereNull('data->category')->whereIn('data->type', $legacy)));
        }
        if ($request->filled('priority')) {
            $priority = (string) $request->query('priority');
            $legacy = array_keys(array_filter(self::LEGACY, fn ($l) => $l[1] === $priority));
            $query->where(fn ($q) => $q->where('data->priority', $priority)
                ->orWhere(fn ($q2) => $q2->whereNull('data->priority')->whereIn('data->type', $legacy)));
        }

        $page = $query->paginate(min(max((int) $request->query('per_page', 30), 1), 100));

        $rows = array_map(fn (DatabaseNotification $n) => $this->row($n), $page->items());

        return ApiResponse::paginated($page, $rows, ['unread' => $this->unread($request)]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return ApiResponse::success(['unread' => $this->unread($request)]);
    }

    /** One UPDATE, however many are unread — the collection is never loaded. */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['unread' => 0]);
    }

    private function unread(Request $request): int
    {
        return $request->user()->unreadNotifications()->count();
    }

    /** @return array<string,mixed> */
    private function row(DatabaseNotification $n): array
    {
        $data = (array) $n->data;
        $type = $data['type'] ?? class_basename($n->type);
        [$category, $priority, $route, $entity, $uuidKey] = self::LEGACY[$type] ?? ['system', 'normal', null, null, null];
        $message = $data['message'] ?? null;

        return [
            'id' => $n->id,
            'type' => $type,
            'category' => $data['category'] ?? $category,
            'priority' => $data['priority'] ?? $priority,
            'title' => $data['title'] ?? 'Notification',
            'message' => $message,
            'public_message' => $data['public_message'] ?? null,
            'private_message' => $data['private_message'] ?? $message,
            'route' => $data['route'] ?? $route,
            'entity' => $data['entity'] ?? $entity,
            'entity_uuid' => $data['entity_uuid'] ?? ($uuidKey !== null ? ($data[$uuidKey] ?? null) : null),
            'dedupe_key' => $data['dedupe_key'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'data' => $data,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
