<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\WhatsApp\Models\FunnelRule;

/**
 * The passage rules: when a deal is won in a pipeline, a card is opened in another one (the post-sale,
 * for instance), with the same contact, the chosen fields, a responsible person and, optionally, the
 * WhatsApp conversation. A failure here never undoes the win.
 */
final class FunnelPassage
{
    /** Fields that belong to the deal itself and are never cloned with the custom ones. */
    private const OWN_ATTRIBUTES = ['title', 'lead_value', 'expected_close_date', 'lead_pipeline_id', 'lead_pipeline_stage_id'];

    public static function handle(Lead $lead): void
    {
        $won = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->where('code', 'won')->exists();

        if (! $won) {
            return;
        }

        $rules = FunnelRule::query()->where('is_active', true)->where('source_pipeline_id', $lead->lead_pipeline_id)->orderBy('id')->get();

        foreach ($rules as $rule) {
            try {
                self::apply($rule, $lead);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @return Lead|null the new card, or null when this deal already went through the rule
     */
    public static function apply(FunnelRule $rule, Lead $source): ?Lead
    {
        $new = DB::transaction(function () use ($rule, $source) {
            $first = DB::table('funnel_rule_runs')->insertOrIgnore([
                'funnel_rule_id' => $rule->id, 'source_lead_id' => $source->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            if (! $first) {
                return null;
            }

            $owner = self::responsible($rule, $source);

            $new = app(LeadRepository::class)->create([
                'entity_type' => 'leads',
                'title' => $source->title,
                'description' => $source->description,
                'person_id' => $source->person_id,
                'lead_source_id' => $source->lead_source_id,
                'lead_type_id' => $source->lead_type_id,
                'lead_value' => $rule->copy_value ? $source->lead_value : 0,
                'user_id' => $owner,
                'lead_pipeline_id' => $rule->target_pipeline_id,
                'lead_pipeline_stage_id' => $rule->target_stage_id,
            ]);

            self::copyCustomFields($source->id, $new->id);
            self::copyTags($source->id, $new->id);

            if ($rule->move_conversation) {
                DB::table('whatsapp_conversations')->where('lead_id', $source->id)->update(['lead_id' => $new->id, 'assigned_user_id' => $owner]);
            }

            DB::table('funnel_rule_runs')->where('funnel_rule_id', $rule->id)->where('source_lead_id', $source->id)->update(['target_lead_id' => $new->id]);

            return $new;
        });

        if ($new) {
            Event::dispatch('lead.create.after', $new);
        }

        return $new;
    }

    private static function responsible(FunnelRule $rule, Lead $source): ?int
    {
        $active = fn (?int $id) => $id && DB::table('users')->where('id', $id)->where('status', 1)->exists() ? $id : null;

        if ($rule->assignment_mode === 'fixed') {
            return $active($rule->assigned_user_id) ?? $source->user_id;
        }

        if ($rule->assignment_mode === 'round_robin') {
            $pool = DB::table('users')->whereIn('id', (array) $rule->pool_user_ids)->where('status', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $picked = AgendaAssignment::pick('round_robin', $pool, $rule->last_assigned_user_id);

            if ($picked !== null) {
                $rule->forceFill(['last_assigned_user_id' => $picked])->save();

                return $picked;
            }
        }

        return $source->user_id;
    }

    private static function copyCustomFields(int $from, int $to): void
    {
        $custom = DB::table('attributes')->where('entity_type', 'leads')->whereNotIn('code', self::OWN_ATTRIBUTES)->pluck('id');

        $rows = DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $from)->whereIn('attribute_id', $custom)->get();

        foreach ($rows as $row) {
            $copy = (array) $row;
            unset($copy['id']);

            DB::table('attribute_values')->insert(array_merge($copy, ['entity_id' => $to, 'unique_id' => null]));
        }
    }

    private static function copyTags(int $from, int $to): void
    {
        $tags = DB::table('lead_tags')->where('lead_id', $from)->pluck('tag_id');

        foreach ($tags as $tagId) {
            DB::table('lead_tags')->insertOrIgnore(['lead_id' => $to, 'tag_id' => $tagId]);
        }
    }
}
