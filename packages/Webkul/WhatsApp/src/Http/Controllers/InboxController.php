<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Pipeline;
use Webkul\Tag\Models\Tag;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Support\InboxPresenter;

class InboxController extends Controller
{
    private const LIST_SIZE = 30;

    private const THREAD_SIZE = 50;

    public function index(): View
    {
        return view('whatsapp::inbox.index', [
            'sessions' => Session::query()->orderBy('name')->get(['id', 'name']),
            'funnelFilters' => $this->funnelFilters(),
            'urls'     => [
                'conversations' => route('admin.whatsapp.inbox.conversations'),
                'messages'      => route('admin.whatsapp.inbox.messages', ['id' => '__ID__']),
                'read'          => route('admin.whatsapp.inbox.read', ['id' => '__ID__']),
                'claim'         => route('admin.whatsapp.inbox.claim', ['id' => '__ID__']),
                'status'        => route('admin.whatsapp.inbox.status', ['id' => '__ID__']),
                'link'          => route('admin.whatsapp.inbox.lead.link', ['id' => '__ID__']),
                'createLead'    => route('admin.whatsapp.inbox.lead.create', ['id' => '__ID__']),
                'send'          => route('admin.whatsapp.inbox.send', ['id' => '__ID__']),
                'retry'         => route('admin.whatsapp.inbox.retry', ['id' => '__ID__']),
                'leadSearch'    => route('admin.whatsapp.inbox.lead.search'),
                'quickReplies'  => route('admin.whatsapp.quick-replies.list'),
                'funnel'        => route('admin.whatsapp.inbox.funnel.show', ['id' => '__ID__']),
                'funnelStage'   => route('admin.whatsapp.inbox.funnel.stage', ['id' => '__ID__']),
                'funnelTags'    => route('admin.whatsapp.inbox.funnel.tags.attach', ['id' => '__ID__']),
                'funnelTagOff'  => route('admin.whatsapp.inbox.funnel.tags.detach', ['id' => '__ID__', 'tagId' => '__TAG__']),
            ],
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tab'        => ['nullable', 'in:queue,mine,all,closed'],
            'search'     => ['nullable', 'string', 'max:100'],
            'unread'     => ['nullable', 'boolean'],
            'session_id' => ['nullable', 'integer'],
            'stage_id'   => ['nullable', 'string', 'regex:/^(none|\d+)$/'],
            'tag_id'     => ['nullable', 'integer'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);

        $userId = (int) auth()->guard('user')->id();

        // Inbound messages newer than what this user has already read in the conversation.
        $unread = fn ($query) => $query
            ->where('whatsapp_messages.direction', 'inbound')
            ->where('whatsapp_messages.id', '>', fn ($sub) => $sub
                ->from('whatsapp_conversation_reads as r')
                ->selectRaw('coalesce(max(r.last_read_message_id), 0)')
                ->whereColumn('r.conversation_id', 'whatsapp_messages.conversation_id')
                ->where('r.user_id', $userId));

        $query = Conversation::query()
            ->whereNotNull('session_id')
            ->with(['lead:id,title,lead_pipeline_stage_id', 'lead.stage:id,name', 'assignedUser:id,name', 'session:id,status'])
            ->withCount(['messages as unread_count' => $unread]);

        match ($filters['tab'] ?? 'queue') {
            'queue'  => $query->where('status', 'open')->whereNull('assigned_user_id'),
            'mine'   => $query->where('status', 'open')->where('assigned_user_id', $userId),
            'all'    => $query->where('status', 'open'),
            'closed' => $query->where('status', 'closed'),
        };

        if (filled($filters['search'] ?? null)) {
            $like = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
            $digits = preg_replace('/\D/', '', $filters['search']);

            $query->where(function ($search) use ($like, $digits) {
                $search->where('contact_name', 'like', $like)->orWhere('last_message_preview', 'like', $like);

                if ($digits !== '') {
                    $search->orWhere('contact_phone', 'like', '%'.$digits.'%');
                }
            });
        }

        if ($request->boolean('unread')) {
            $query->whereHas('messages', $unread);
        }

        if (! empty($filters['session_id'])) {
            $query->where('session_id', $filters['session_id']);
        }

        // "none" = conversations still without a lead; a number = leads at that pipeline stage.
        if (($filters['stage_id'] ?? null) === 'none') {
            $query->whereNull('lead_id');
        } elseif (! empty($filters['stage_id'])) {
            $query->whereHas('lead', fn ($lead) => $lead->where('lead_pipeline_stage_id', (int) $filters['stage_id']));
        }

        if (! empty($filters['tag_id'])) {
            $query->whereHas('lead.tags', fn ($tags) => $tags->where('tags.id', (int) $filters['tag_id']));
        }

        $offset = (((int) ($filters['page'] ?? 1)) - 1) * self::LIST_SIZE;

        $rows = $query
            ->orderByRaw('coalesce(last_message_at, created_at) desc')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit(self::LIST_SIZE + 1)
            ->get();

        return response()->json([
            'data'     => $rows->take(self::LIST_SIZE)->map(fn (Conversation $conversation) => $this->conversationData($conversation))->values(),
            'has_more' => $rows->count() > self::LIST_SIZE,
        ]);
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        $conversation = $this->conversation($id);

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::THREAD_SIZE + 1);

        if ($request->filled('before_id')) {
            $query->where('id', '<', (int) $request->query('before_id'));
        }

        $rows = $query->get();

        return response()->json([
            'data'     => $rows->take(self::THREAD_SIZE)->reverse()->values()->map(fn (Message $message) => InboxPresenter::message($message)),
            'has_more' => $rows->count() > self::THREAD_SIZE,
        ]);
    }

    public function markRead(int $id): JsonResponse
    {
        $conversation = $this->conversation($id);

        $lastInboundId = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->max('id');

        if ($lastInboundId) {
            DB::table('whatsapp_conversation_reads')->upsert([[
                'conversation_id'      => $conversation->id,
                'user_id'              => auth()->guard('user')->id(),
                'last_read_message_id' => $lastInboundId,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]], ['conversation_id', 'user_id'], ['last_read_message_id', 'updated_at']);
        }

        return response()->json(['unread' => 0]);
    }

    public function claim(Request $request, int $id): JsonResponse
    {
        $conversation = $this->conversation($id);
        $user = auth()->guard('user')->user();

        $conversation->assigned_user_id = $request->boolean('release') ? null : $user->id;
        $conversation->save();

        return response()->json([
            'assigned' => $conversation->assigned_user_id ? ['id' => $user->id, 'name' => $user->name] : null,
        ]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,closed']]);

        $conversation = $this->conversation($id);
        $conversation->status = $data['status'];
        $conversation->save();

        return response()->json(['status' => $conversation->status]);
    }

    private function conversation(int $id): Conversation
    {
        return Conversation::query()->whereNotNull('session_id')->findOrFail($id);
    }

    private function conversationData(Conversation $conversation): array
    {
        return [
            'id'         => $conversation->id,
            'session_id' => $conversation->session_id,
            'session_status' => $conversation->session?->status,
            'name'       => $conversation->contact_name,
            'phone'      => $conversation->contact_phone,
            'status'     => $conversation->status,
            'preview'    => $conversation->last_message_preview,
            'unread'     => (int) $conversation->unread_count,
            'sort_at'    => ($conversation->last_message_at ?? $conversation->created_at)?->toIso8601String(),
            'lead'       => $conversation->lead ? [
                'id'    => $conversation->lead->id,
                'title' => $conversation->lead->title,
                'url'   => route('admin.leads.view', $conversation->lead->id),
                'stage' => $conversation->lead->stage?->name,
            ] : null,
            'assigned' => $conversation->assignedUser
                ? ['id' => $conversation->assignedUser->id, 'name' => $conversation->assignedUser->name]
                : null,
        ];
    }

    /**
     * Stages and tags the list can be filtered by. The pipeline name is only added to a
     * stage when there is more than one pipeline, to tell apart stages named the same.
     *
     * @return array{stages: list<array{id: int, name: string}>, tags: list<array{id: int, name: string, color: ?string}>}
     */
    private function funnelFilters(): array
    {
        $pipelines = Pipeline::query()->with(['stages' => fn ($query) => $query->orderBy('sort_order')])->orderBy('id')->get();
        $several = $pipelines->count() > 1;

        $stages = $pipelines->flatMap(fn ($pipeline) => $pipeline->stages->map(fn ($stage) => [
            'id'   => $stage->id,
            'name' => $several ? $pipeline->name.' · '.$stage->name : $stage->name,
        ]))->values();

        return [
            'stages' => $stages,
            'tags'   => Tag::query()->orderBy('name')->limit(200)->get(['id', 'name', 'color']),
        ];
    }
}
