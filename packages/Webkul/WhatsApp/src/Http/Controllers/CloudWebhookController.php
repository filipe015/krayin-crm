<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Webkul\WhatsApp\Channels\Cloud\CloudSignature;
use Webkul\WhatsApp\Channels\Cloud\CloudWebhookParser;
use Webkul\WhatsApp\Channels\Waha\WahaEventHandler;

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

        foreach (CloudWebhookParser::parse((array) $request->json()->all()) as $event) {
            $this->handler->handle($event);
        }

        return response()->json(['received' => true]);
    }
}
