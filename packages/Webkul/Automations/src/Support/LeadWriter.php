<?php

namespace Webkul\Automations\Support;

use Webkul\Lead\Models\Lead;
use Webkul\Lead\Services\PipelineTransfer;

/**
 * Changes a lead the way the board and the lead page do. The logic now lives in the Lead package
 * (Webkul\Lead\Services\PipelineTransfer), shared with the quick pipeline/stage switcher of the board
 * and the lead page; this class stays as the automations' entry point so nothing else changes here.
 */
final class LeadWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function update(Lead $lead, array $data, bool $keepClosedAt = false): Lead
    {
        return PipelineTransfer::write($lead, $data, $keepClosedAt);
    }
}
