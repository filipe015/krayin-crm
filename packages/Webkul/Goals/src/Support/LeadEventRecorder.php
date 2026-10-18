<?php

namespace Webkul\Goals\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Goals\Models\LeadEvent;

/**
 * Writes what happens to a deal into lead_events. The one place that knows the table's shape.
 * A failure here must never get in the way of saving the deal, so it only logs.
 */
final class LeadEventRecorder
{
    /**
     * @param  array<string, mixed>  $attributes  pipeline_id, stage_id, tag_id, activity_id
     */
    public static function record(int $leadId, string $kind, ?Carbon $at = null, array $attributes = []): void
    {
        try {
            LeadEvent::query()->create($attributes + [
                'lead_id'     => $leadId,
                'kind'        => $kind,
                'occurred_at' => $at ?? now(),
                'user_id'     => auth()->guard('user')->id(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public static function tagAdded(int $leadId, int $tagId): void
    {
        self::record($leadId, LeadEvent::TAG_ADDED, null, ['tag_id' => $tagId]);
    }

    /**
     * Only meetings count as appointments; notes, tasks and the like are ignored.
     */
    public static function activityAttached(int $leadId, int $activityId): void
    {
        $activity = DB::table('activities')->where('id', $activityId)->first(['type', 'created_at']);

        if (! $activity || $activity->type !== 'meeting') {
            return;
        }

        self::record($leadId, LeadEvent::APPOINTMENT_CREATED, Carbon::parse($activity->created_at), ['activity_id' => $activityId]);
    }
}
