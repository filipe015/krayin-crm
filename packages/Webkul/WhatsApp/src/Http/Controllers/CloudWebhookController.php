<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\WhatsApp\Channels\Cloud\CloudSignature;
use Webkul\WhatsApp\Channels\Cloud\CloudWebhookParser;
use Webkul\WhatsApp\Channels\Waha\WahaEventHandler;
use Webkul\WhatsApp\Models\Session;

class CloudWebhookController extends Controller
{
    public function __construct(protected WahaEventHandler $handler) {}

    /**
     * Meta's one-off check when the callback URL is saved in the app. PHP turns the dots
     * of "hub.mode" into underscores, hence the names below.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('whatsapp.cloud.verify_token');
        $given = (string) $request->query('hub_verify_token', '');

        if ($request->query('hub_mode') === 'subscribe' && $expected !== '' && hash_equals($expected, $given)) {
            return response((string) $request->query('hub_challenge', ''), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $valid = CloudSignature::isValid(
            $request->getContent(),
            $request->header('X-Hub-Signature-256'),
            (string) config('whatsapp.cloud.app_secret'),
        );

        if (! $valid) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = (array) $request->json()->all();

        foreach (CloudWebhookParser::parse($payload) as $event) {
            $this->handler->handle($event);
        }

        $this->notices($payload);

        return response()->json(['received' => true]);
    }

    /**
     * Events that carry no conversation message. Coexistence syncs (chat history and contacts
     * of the WhatsApp Business app) are only counted in the log, never their content; a
     * template review result refreshes the template list.
     *
     * @param  array<string, mixed>  $payload
     */
    private function notices(array $payload): void
    {
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $field = (string) ($change['field'] ?? '');
                $value = (array) ($change['value'] ?? []);

                if (in_array($field, ['history', 'smb_app_state_sync', 'smb_message_echoes'], true)) {
                    Log::info('whatsapp.cloud.coexistence', [
                        'field'           => $field,
                        'waba_id'         => (string) ($entry['id'] ?? ''),
                        'phone_number_id' => (string) ($value['metadata']['phone_number_id'] ?? ''),
                        'items'           => count((array) ($value['history'] ?? $value['state_sync'] ?? $value['message_echoes'] ?? [])),
                    ]);
                } elseif ($field === 'message_template_status_update') {
                    Session::query()->where('channel', 'cloud')->pluck('id')
                        ->each(fn ($id) => Cache::forget('whatsapp.cloud.templates.'.$id));
                }
            }
        }
    }
}
