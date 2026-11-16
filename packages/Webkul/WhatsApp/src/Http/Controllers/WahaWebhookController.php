<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\WhatsApp\Channels\Waha\WahaEventHandler;
use Webkul\WhatsApp\Channels\Waha\WahaSignature;
use Webkul\WhatsApp\Channels\Waha\WahaWebhookParser;
use Webkul\WhatsApp\Support\WahaSessions;

class WahaWebhookController extends Controller
{
    public function __construct(protected WahaEventHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $valid = WahaSignature::isValid(
            $request->getContent(),
            $request->header('X-Webhook-Hmac'),
            (string) config('whatsapp.waha.hmac_secret'),
        );

        if (! $valid) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        $envelope = $request->json()->all();
        $session = (string) ($envelope['session'] ?? '');

        // A WAHA server shared by several installations: events of another CRM's session
        // are acknowledged (so WAHA does not retry) and dropped.
        if ($session !== '' && ! WahaSessions::allows($session)) {
            Log::debug('WhatsApp: WAHA webhook ignored, session not in WAHA_SESSIONS.', [
                'session' => $session,
                'event'   => $envelope['event'] ?? null,
            ]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        $event = WahaWebhookParser::parse($envelope);

        if ($event !== null) {
            $this->handler->handle($event);
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
