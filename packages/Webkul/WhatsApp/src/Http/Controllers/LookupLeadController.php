<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Lead\Models\Lead;

class LookupLeadController extends Controller
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
            'phone' => ['required', 'string', 'max:50'],
        ]);

        $phone = preg_replace('/\D/', '', $data['phone']);

        if ($phone === '') {
            return response()->json([
                'message' => 'The phone number must contain at least one digit.',
            ], 422);
        }

        $lead = Lead::query()
            ->join('persons', 'persons.id', '=', 'leads.person_id')
            ->whereNotNull('persons.contact_numbers')
            ->select('leads.id', 'leads.created_at', 'persons.contact_numbers')
            ->latest('leads.created_at')
            ->latest('leads.id')
            ->cursor()
            ->first(function (Lead $lead) use ($phone) {
                $contactNumbers = json_decode((string) $lead->contact_numbers, true);

                if (! is_array($contactNumbers)) {
                    return false;
                }

                foreach ($contactNumbers as $contactNumber) {
                    $value = is_array($contactNumber)
                        ? ($contactNumber['value'] ?? null)
                        : $contactNumber;

                    if ($value !== null && preg_replace('/\D/', '', (string) $value) === $phone) {
                        return true;
                    }
                }

                return false;
            });

        if (! $lead) {
            return response()->json([
                'message' => 'No lead was found for the provided phone number.',
            ], 404);
        }

        return response()->json([
            'lead_id' => $lead->id,
        ]);
    }
}
