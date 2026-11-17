<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Lead\Models\Lead;

/**
 * Changes a lead the way the board and the lead page do: the "lead.update.before/after" events go
 * out (native workflows, outgoing API webhooks), the model events fire (Goals' lead_events, the
 * lead's history, the automations themselves), "stage_entered_at" is stamped on a real stage change
 * and "closed_at" follows LeadRepository::update — set on won/lost, cleared on an open stage —
 * except when $keepClosedAt asks to keep it (a transfer of an already won/lost lead).
 */
final class LeadWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function update(Lead $lead, array $data, bool $keepClosedAt = false): Lead
    {
        if (array_key_exists('lead_pipeline_stage_id', $data) && (int) $data['lead_pipeline_stage_id'] !== (int) $lead->lead_pipeline_stage_id) {
            $code = DB::table('lead_pipeline_stages')->where('id', $data['lead_pipeline_stage_id'])->value('code');

            $data['stage_entered_at'] = now();

            if (in_array($code, ['won', 'lost'], true)) {
                $data['closed_at'] = $data['closed_at'] ?? now();
            } elseif (! $keepClosedAt) {
                $data['closed_at'] = null;
            }
        }

        Event::dispatch('lead.update.before', $lead->id);

        $lead->forceFill($data)->save();

        Event::dispatch('lead.update.after', $lead);

        return $lead;
    }
}
