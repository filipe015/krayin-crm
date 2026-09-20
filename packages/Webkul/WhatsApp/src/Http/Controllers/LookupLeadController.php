<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\WhatsApp\Support\LeadPhoneFinder;

class LookupLeadController extends Controller
{
    public function __construct(protected LeadPhoneFinder $leadPhoneFinder) {}

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
            'phone' => ['required', 'string', 'max:50'],
        ]);

        $phone = preg_replace('/\D/', '', $data['phone']);

        if ($phone === '') {
            return response()->json([
                'message' => 'The phone number must contain at least one digit.',
            ], 422);
        }

        $leadId = $this->leadPhoneFinder->find($phone);

        if ($leadId === null) {
            return response()->json([
                'message' => 'No lead was found for the provided phone number.',
            ], 404);
        }

        return response()->json([
            'lead_id' => $leadId,
        ]);
    }
}
