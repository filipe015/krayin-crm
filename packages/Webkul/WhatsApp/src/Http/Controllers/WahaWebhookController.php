<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\WhatsApp\Channels\Waha\WahaEventHandler;
use Webkul\WhatsApp\Channels\Waha\WahaSignature;
use Webkul\WhatsApp\Channels\Waha\WahaWebhookParser;

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

        $event = WahaWebhookParser::parse($request->json()->all());

        if ($event !== null) {
            $this->handler->handle($event);
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
