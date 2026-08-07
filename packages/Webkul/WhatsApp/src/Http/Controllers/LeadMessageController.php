<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\WhatsApp\Models\Message;

class LeadMessageController extends Controller
{
    public function __construct(protected LeadRepository $leadRepository) {}

    public function index(int $leadId): JsonResponse
    {
        $this->leadRepository->findOrFail($leadId);

        $messages = Message::query()
            ->whereHas('conversation', fn ($query) => $query->where('lead_id', $leadId))
            ->with('conversation:id,canal_origem')
            ->oldest('created_at')
            ->oldest('id')
            ->get()
            ->map(fn (Message $message) => [
                'id'             => $message->id,
                'direction'      => $message->direction,
                'sender_name'    => $message->sender_name,
                'content'        => $message->content,
                'message_type'   => $message->message_type,
                'canal_origem'   => $message->conversation->canal_origem,
                'occurred_at'    => $message->created_at?->toISOString(),
            ]);

        return response()->json([
            'data' => $messages,
        ]);
    }
}
