<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Support\PhoneNumberMatcher;

/**
 * "Open WhatsApp" on a lead: takes the operator to that lead's conversation in the Inbox,
 * starting one when the lead has never talked to the WhatsApp number yet.
 */
class OpenLeadConversationController extends Controller
{
    public function __invoke(int $leadId): RedirectResponse
    {
        $userIds = bouncer()->getAuthorizedUserIds();

        $lead = Lead::query()
            ->with('person:id,name,contact_numbers')
            ->when($userIds, fn ($query) => $query->whereIn('leads.user_id', $userIds))
            ->findOrFail($leadId);

        $conversation = Conversation::query()
            ->whereNotNull('session_id')
            ->where('lead_id', $lead->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        $phone = $this->phoneOf($lead);

        // A chat with this number that nobody linked to a lead yet becomes this lead's.
        if ($conversation === null && $phone !== null) {
            $conversation = $this->unlinkedConversation($phone);

            if ($conversation !== null) {
                $conversation->lead_id = $lead->id;
                $conversation->save();
            }
        }

        if ($conversation === null) {
            abort_if($phone === null, 422, trans('whatsapp::app.inbox.lead_without_phone'));

            $session = Session::query()->where('channel', 'waha')->where('status', 'WORKING')->orderBy('id')->first()
                ?? Session::query()->where('channel', 'waha')->orderBy('id')->first();

            abort_if($session === null, 422, trans('whatsapp::app.inbox.no_session'));

            $conversation = Conversation::query()->firstOrCreate(
                ['session_id' => $session->id, 'chat_id' => $phone.'@c.us'],
                [
                    'canal_origem'     => 'waha',
                    'contact_phone'    => $phone,
                    'contact_name'     => $lead->person?->name,
                    'lead_id'          => $lead->id,
                    'assigned_user_id' => auth()->guard('user')->id(),
                    'status'           => 'open',
                ],
            );

            if ($conversation->lead_id === null) {
                $conversation->lead_id = $lead->id;
                $conversation->save();
            }
        }

        return redirect()->route('admin.whatsapp.inbox.index', ['c' => $conversation->id]);
    }

    /**
     * First contact number of the lead's person, digits only and with the Brazilian
     * country code, exactly as the old wa.me button did.
     */
    private function phoneOf(Lead $lead): ?string
    {
        $numbers = $lead->person?->contact_numbers;
        $first = is_array($numbers) ? ($numbers[0]['value'] ?? null) : null;
        $digits = preg_replace('/\D/', '', (string) $first);

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '55') ? $digits : '55'.$digits;
    }

    private function unlinkedConversation(string $phone): ?Conversation
    {
        return Conversation::query()
            ->whereNotNull('session_id')
            ->whereNull('lead_id')
            ->whereIn('contact_phone', PhoneNumberMatcher::variants($phone))
            ->orderByDesc('last_message_at')
            ->first();
    }
}
