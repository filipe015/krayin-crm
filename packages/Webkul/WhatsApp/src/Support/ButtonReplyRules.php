<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Facades\Event;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\WhatsApp\Models\ButtonRule;
use Webkul\WhatsApp\Models\Conversation;

/**
 * Applies the funnel rules configured for a template button (stage and/or tag) to the lead
 * of the conversation when the customer taps it.
 */
final class ButtonReplyRules
{
    public static function apply(Conversation $conversation, string $text): void
    {
        $text = mb_strtolower(trim($text));

        if ($text === '' || $conversation->lead_id === null) {
            return;
        }

        $rule = ButtonRule::query()->whereRaw('LOWER(match_text) = ?', [$text])->first();

        if ($rule === null) {
            return;
        }

        $lead = Lead::query()->with('pipeline.stages')->find($conversation->lead_id);

        if ($lead === null) {
            return;
        }

        // Only a stage of the lead's own pipeline is a valid target.
        $stage = $rule->stage_id !== null ? $lead->pipeline?->stages->firstWhere('id', $rule->stage_id) : null;

        if ($stage !== null && $lead->lead_pipeline_stage_id !== $stage->id) {
            Event::dispatch('lead.update.before', $lead->id);

            $updated = app(LeadRepository::class)->update(
                ['entity_type' => 'leads', 'lead_pipeline_stage_id' => $stage->id],
                $lead->id,
                ['lead_pipeline_stage_id'],
            );

            Event::dispatch('lead.update.after', $updated);
        }

        if ($rule->tag_id !== null) {
            $lead->tags()->syncWithoutDetaching([$rule->tag_id]);
        }
    }
}
