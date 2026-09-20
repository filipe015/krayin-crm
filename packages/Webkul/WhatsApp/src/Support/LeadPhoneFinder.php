<?php

namespace Webkul\WhatsApp\Support;

use Webkul\Lead\Models\Lead;

class LeadPhoneFinder
{
    /**
     * Newest lead whose person has a contact number matching the given phone.
     */
    public function find(string $phone): ?int
    {
        $phone = preg_replace('/\D/', '', $phone);

        if ($phone === '') {
            return null;
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

                    if ($value !== null && PhoneNumberMatcher::matches((string) $value, $phone)) {
                        return true;
                    }
                }

                return false;
            });

        return $lead?->id;
    }
}
