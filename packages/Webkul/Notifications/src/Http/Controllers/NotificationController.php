<?php

namespace Webkul\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\Notifications\Models\Notification;

/**
 * The bell: the latest notifications of the logged-in user, and marking them as read.
 */
class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $userId = $this->userId();

        $items = Notification::query()->visibleTo($userId)->orderByDesc('id')->limit(20)->get();

        $read = DB::table('crm_notification_reads')
            ->where('user_id', $userId)
            ->whereIn('notification_id', $items->pluck('id'))
            ->pluck('notification_id')
            ->flip();

        return response()->json([
            'unread'    => Notification::query()->visibleTo($userId)->unreadBy($userId)->count(),
            'latest_id' => (int) Notification::query()->visibleTo($userId)->max('id'),
            'items'     => $items->map(fn (Notification $item) => [
                'id'      => $item->id,
                'kind'    => $item->kind,
                'level'   => $item->level,
                'title'   => $item->title,
                'body'    => $item->body,
                'url'     => $item->url,
                'read'    => $read->has($item->id),
                'created' => $item->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        $this->markRead(Notification::query()->visibleTo($this->userId())->whereKey($data['id'])->pluck('id')->all());

        return response()->json(['ok' => true]);
    }

    public function readAll(): JsonResponse
    {
        $userId = $this->userId();

        $this->markRead(Notification::query()->visibleTo($userId)->unreadBy($userId)->pluck('id')->all());

        return response()->json(['ok' => true]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function markRead(array $ids): void
    {
        if (! $ids) {
            return;
        }

        $userId = $this->userId();
        $now = now();

        DB::table('crm_notification_reads')->insertOrIgnore(array_map(fn ($id) => [
            'notification_id' => $id,
            'user_id'         => $userId,
            'read_at'         => $now,
        ], $ids));
    }

    private function userId(): int
    {
        return (int) auth()->guard('user')->id();
    }
}
