<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\Lead\Models\Pipeline;
use Webkul\Lead\Models\Stage;
use Webkul\WhatsApp\Models\Conversation;

/**
 * Opens a lead by itself when a contact writes in a conversation without one
 * (WHATSAPP_AUTO_LEAD = off | ads | all). The caller only calls it for a message received
 * from the contact (not sent by us, never a group or status: those never become a
 * conversation). It never throws: a failure is logged and the message is kept.
 */
class AutoLead
{
    public const MODES = ['off', 'ads', 'all'];

    public function __construct(protected ConversationLead $service) {}

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('whatsapp.auto_lead.mode', 'off')));

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public static function applies(Conversation $conversation): bool
    {
        return match (self::mode()) {
            'all'   => true,
            'ads'   => $conversation->ad_captured_at !== null,
            default => false,
        };
    }

    /**
     * @return int|null  The lead the conversation ends up linked to (created or existing).
     */
    public function handle(Conversation $conversation): ?int
    {
        if ($conversation->lead_id !== null || ! self::applies($conversation)) {
            return $conversation->lead_id;
        }

        try {
            // Keyed by number (last 8 digits: same with or without the ninth digit and the
            // country code), so the same contact on two numbers or a repeated webhook
            // cannot open two leads at once.
            $phone = ConversationLead::phone($conversation);
            $key = 'whatsapp.auto-lead.'.($phone !== '' ? substr($phone, -8) : 'c'.$conversation->id);

            return Cache::lock($key, 30)->block(10, fn () => $this->open($conversation));
        } catch (LockTimeoutException) {
            Log::warning('WhatsApp: automatic lead skipped, another one was being created for the same contact.', ['conversation_id' => $conversation->id]);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp: the automatic lead could not be created.', [
                'conversation_id' => $conversation->id,
                'error'           => $exception->getMessage(),
            ]);
        }

        return null;
    }

    private function open(Conversation $conversation): ?int
    {
        // Another delivery of the same webhook may have finished while this one waited.
        $fresh = Conversation::query()->find($conversation->id);

        if ($fresh === null) {
            return null;
        }

        if ($fresh->lead_id !== null) {
            $conversation->lead_id = $fresh->lead_id;

            return $fresh->lead_id;
        }

        $existingId = $this->service->existingLeadId($fresh);

        if ($existingId !== null) {
            $fresh->lead_id = $existingId;
            $fresh->save();
            $conversation->lead_id = $existingId;

            return $existingId;
        }

        [$pipeline, $stage] = $this->placement();

        $fromAd = $fresh->ad_captured_at !== null;
        $label = ConversationLead::label($fresh, trans('whatsapp::app.inbox.unknown'));
        $source = trim((string) config('whatsapp.auto_lead.source'));
        $userId = $fresh->assigned_user_id ?? (filled(config('whatsapp.auto_lead.user_id')) ? (int) config('whatsapp.auto_lead.user_id') : null);

        $lead = $this->service->open($fresh, [
            'title'       => $label.' - WhatsApp'.($fromAd && filled($fresh->ad_title) ? ' ('.$fresh->ad_title.')' : ''),
            'description' => $this->description($fresh),
            'source'      => $source !== '' ? $source : ($fromAd ? 'WhatsApp Anúncio' : 'WhatsApp'),
            'tags'        => array_filter(array_map('trim', explode(',', (string) config('whatsapp.auto_lead.tags', '')))),
            'user_id'     => $userId,
            'pipeline'    => $pipeline,
            'stage'       => $stage,
        ]);

        $conversation->lead_id = $lead->id;

        return $lead->id;
    }

    /**
     * Pipeline: WHATSAPP_AUTO_LEAD_PIPELINE_ID or the default one. Stage: by id, else by
     * name (WHATSAPP_AUTO_LEAD_STAGE) inside that pipeline, else its first stage.
     *
     * @return array{0: ?Pipeline, 1: ?Stage}
     */
    public function placement(): array
    {
        $pipelineId = config('whatsapp.auto_lead.pipeline_id');
        $pipeline = filled($pipelineId) ? Pipeline::query()->find((int) $pipelineId) : null;

        if (filled($pipelineId) && $pipeline === null) {
            Log::warning('WhatsApp: WHATSAPP_AUTO_LEAD_PIPELINE_ID not found, using the default pipeline.', ['pipeline_id' => $pipelineId]);
        }

        $pipeline ??= $this->service->defaultPlacement()[0] ?? null;

        if ($pipeline === null) {
            return [null, null];
        }

        $stages = $pipeline->stages()->orderBy('sort_order')->get();
        $stageId = config('whatsapp.auto_lead.stage_id');
        $stageName = trim((string) config('whatsapp.auto_lead.stage'));
        $stage = null;

        if (filled($stageId)) {
            $stage = $stages->firstWhere('id', (int) $stageId);

            if ($stage === null) {
                Log::warning('WhatsApp: WHATSAPP_AUTO_LEAD_STAGE_ID is not a stage of the pipeline.', ['stage_id' => $stageId, 'pipeline_id' => $pipeline->id]);
            }
        }

        if ($stage === null && $stageName !== '') {
            $wanted = mb_strtolower($stageName);
            $stage = $stages->first(fn (Stage $candidate) => mb_strtolower(trim((string) $candidate->name)) === $wanted);

            if ($stage === null) {
                Log::warning('WhatsApp: WHATSAPP_AUTO_LEAD_STAGE not found in the pipeline, using its first stage.', ['stage' => $stageName, 'pipeline_id' => $pipeline->id]);
            }
        }

        return [$pipeline, $stage ?? $stages->first()];
    }

    private function description(Conversation $conversation): string
    {
        $lines = [trans('whatsapp::app.inbox.auto_lead_description')];

        if ($conversation->ad_captured_at !== null) {
            $lines[] = '';
            $lines[] = trans('whatsapp::app.inbox.ad_origin').':';

            foreach ([
                'ad_title'      => 'ad_title',
                'ad_source_id'  => 'ad_id',
                'ad_source_url' => 'ad_link',
                'ad_ctwa_clid'  => 'ad_ctwa_clid',
            ] as $column => $label) {
                if (filled($conversation->{$column})) {
                    $lines[] = trans('whatsapp::app.inbox.'.$label).': '.$conversation->{$column};
                }
            }
        }

        return implode("\n", $lines);
    }
}
