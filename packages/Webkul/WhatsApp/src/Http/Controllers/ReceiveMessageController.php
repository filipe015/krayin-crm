<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;

class ReceiveMessageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredSecret = (string) config('whatsapp.webhook_secret');
        $providedSecret = (string) $request->header('X-Webhook-Secret');

        if ($configuredSecret === '' || $providedSecret === '' || ! hash_equals($configuredSecret, $providedSecret)) {
            return response()->json([
                'message' => 'Invalid webhook credentials.',
            ], 401);
        }

        $data = $request->validate([
            'lead_id'             => ['required', 'integer', 'exists:leads,id'],
            'canal_origem'        => ['required', 'string', 'max:255'],
            'direction'           => ['required', 'in:inbound,outbound'],
            'sender_name'         => ['nullable', 'string', 'max:255'],
            'content'             => ['required', 'string'],
            'message_type'        => ['required', 'string', 'max:100'],
            'external_message_id' => ['nullable', 'string', 'max:255'],
            'occurred_at'         => ['required', 'date'],
        ]);

        try {
            $result = DB::transaction(function () use ($data) {
                $conversation = Conversation::query()->firstOrCreate([
                    'lead_id'      => $data['lead_id'],
                    'canal_origem' => $data['canal_origem'],
                ]);

                if (! empty($data['external_message_id'])) {
                    $duplicate = Message::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('external_message_id', $data['external_message_id'])
                        ->exists();

                    if ($duplicate) {
                        return ['duplicate' => true];
                    }
                }

                $occurredAt = Carbon::parse($data['occurred_at']);

                $message = Message::query()->create([
                    'conversation_id'    => $conversation->id,
                    'direction'          => $data['direction'],
                    'sender_name'        => $data['sender_name'] ?? null,
                    'content'            => $data['content'],
                    'message_type'       => $data['message_type'],
                    'external_message_id' => $data['external_message_id'] ?? null,
                    'created_at'         => $occurredAt,
                    'updated_at'         => $occurredAt,
                ]);

                return [
                    'duplicate' => false,
                    'message_id' => $message->id,
                ];
            });
        } catch (QueryException $exception) {
            $messageAlreadyExists = ! empty($data['external_message_id'])
                && Message::query()
                    ->whereHas('conversation', function ($query) use ($data) {
                        $query->where('lead_id', $data['lead_id'])
                            ->where('canal_origem', $data['canal_origem']);
                    })
                    ->where('external_message_id', $data['external_message_id'])
                    ->exists();

            if ($messageAlreadyExists) {
                return response()->json([
                    'received'  => true,
                    'duplicate' => true,
                ]);
            }

            throw $exception;
        }

        if ($result['duplicate']) {
            return response()->json([
                'received'  => true,
                'duplicate' => true,
            ]);
        }

        return response()->json([
            'received'   => true,
            'duplicate'  => false,
            'message_id' => $result['message_id'],
        ], 201);
    }
}
