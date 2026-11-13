<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\WhatsApp\Support\MetaSignedRequest;

/**
 * Data deletion callback configured in the Meta app (Settings → Basic → "Data deletion request URL").
 */
class MetaDataDeletionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = MetaSignedRequest::secret();
        $payload = MetaSignedRequest::parse($request->input('signed_request'), $secret);
        $userId = is_array($payload) ? (string) ($payload['user_id'] ?? '') : '';

        if ($userId === '') {
            Log::warning('Meta data deletion: signed_request inválido ou sem user_id.', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signed_request.'], 400);
        }

        $code = MetaSignedRequest::confirmationCode($userId, $secret);

        Log::info('Meta data deletion: solicitação recebida.', [
            'user_id' => $userId,
            'confirmation_code' => $code,
            'issued_at' => $payload['issued_at'] ?? null,
        ]);

        return response()->json([
            'url' => rtrim((string) config('app.url'), '/').'/exclusao-de-dados/status/'.$code,
            'confirmation_code' => $code,
        ]);
    }
}
