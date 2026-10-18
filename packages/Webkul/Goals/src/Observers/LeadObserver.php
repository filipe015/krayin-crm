<?php

namespace Webkul\Goals\Observers;

use Webkul\Goals\Models\LeadEvent;
use Webkul\Goals\Support\LeadEventRecorder;
use Webkul\Lead\Models\Lead;

/**
 * A deal being created, and a deal changing stage, from wherever it happens (the board, the form,
 * the API, an import): they all go through the model.
 */
class LeadObserver
{
    public function created(Lead $lead): void
    {
        LeadEventRecorder::record($lead->id, LeadEvent::CREATED, $lead->created_at, [
            'pipeline_id' => $lead->lead_pipeline_id,
            'stage_id'    => $lead->lead_pipeline_stage_id,
        ]);

        $this->enteredStage($lead);
    }

    public function updated(Lead $lead): void
    {
        if ($lead->wasChanged('lead_pipeline_stage_id')) {
            $this->enteredStage($lead);
        }
    }

    private function enteredStage(Lead $lead): void
    {
        LeadEventRecorder::record($lead->id, LeadEvent::STAGE_ENTERED, $lead->stage_entered_at ?? null, [
            'pipeline_id' => $lead->lead_pipeline_id,
            'stage_id'    => $lead->lead_pipeline_stage_id,
        ]);
    }
}
