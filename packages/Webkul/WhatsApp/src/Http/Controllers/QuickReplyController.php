<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Webkul\WhatsApp\Models\QuickReply;

class QuickReplyController extends Controller
{
    public function index(): View
    {
        return view('whatsapp::quick-replies.index', [
            'urls' => [
                'list'   => route('admin.whatsapp.quick-replies.list'),
                'store'  => route('admin.whatsapp.quick-replies.store'),
                'update' => route('admin.whatsapp.quick-replies.update', ['id' => '__ID__']),
                'delete' => route('admin.whatsapp.quick-replies.delete', ['id' => '__ID__']),
                'inbox'  => route('admin.whatsapp.inbox.index'),
            ],
        ]);
    }

    public function list(): JsonResponse
    {
        return response()->json([
            'data' => QuickReply::query()->orderBy('shortcut')->get(['id', 'shortcut', 'title', 'body']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $reply = QuickReply::query()->create($this->validated($request) + [
            'created_by_user_id' => auth()->guard('user')->id(),
        ]);

        return response()->json(['data' => $reply->only(['id', 'shortcut', 'title', 'body'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $reply = QuickReply::query()->findOrFail($id);
        $reply->update($this->validated($request, $reply->id));

        return response()->json(['data' => $reply->only(['id', 'shortcut', 'title', 'body'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        QuickReply::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{shortcut: string, title: string, body: string}
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['shortcut' => ltrim(strtolower((string) $request->input('shortcut')), '/')]);

        return $request->validate([
            'shortcut' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,30}$/', Rule::unique('whatsapp_quick_replies', 'shortcut')->ignore($ignoreId)],
            'title'    => ['required', 'string', 'max:80'],
            'body'     => ['required', 'string', 'max:4096'],
        ], [
            'shortcut.regex'  => trans('whatsapp::app.quick_replies.shortcut_invalid'),
            'shortcut.unique' => trans('whatsapp::app.quick_replies.shortcut_taken'),
        ]);
    }
}
