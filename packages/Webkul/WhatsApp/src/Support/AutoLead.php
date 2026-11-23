<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\Lead\Models\Pipeline;
use Webkul\Lead\Models\Stage;
use Webkul\WhatsApp\Models\Conversation;

/**
 * Opens a lead by itself when a contact writes in a conversation without one (mode manual |
 * ads | all, from LeadCreationSettings: the Configurações screen once saved, else the
 * WHATSAPP_AUTO_LEAD* env, where the legacy "off" means manual). The caller only calls it for a message received
 * from the contact (not sent by us, never a group or status: those never become a
 * conversation). It never throws: a failure is logged and the message is kept.
 */
class AutoLead
{
    public const MODES = LeadCreationSettings::MODES;

    /**
     * Lead created by the last handle() call (null when it only linked one or did nothing),
     * so the caller does not also send a "new message" notice for the same message.
     */
    public ?int $createdLeadId = null;

    public function __construct(protected ConversationLead $service, protected AgentNotifier $notifier) {}

    public static function mode(): string
    {
        return LeadCreationSettings::mode();
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
        $this->createdLeadId = null;

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

        $settings = LeadCreationSettings::current();

        [$pipeline, $stage] = $this->placement($settings);

        $fromAd = $fresh->ad_captured_at !== null;
        $label = ConversationLead::label($fresh, trans('whatsapp::app.inbox.unknown'));
        $source = (string) $settings['source'];
        $userId = $fresh->assigned_user_id ?? $settings['user_id'];

        $lead = $this->service->open($fresh, [
            'title'       => $label.' - WhatsApp'.($fromAd && filled($fresh->ad_title) ? ' ('.$fresh->ad_title.')' : ''),
            'description' => $this->description($fresh),
            'source'      => $source !== '' ? $source : ($fromAd ? 'WhatsApp Anúncio' : 'WhatsApp'),
            'tags'        => $settings['tags'],
            'user_id'     => $userId,
            'pipeline'    => $pipeline,
            'stage'       => $stage,
        ]);

        $conversation->lead_id = $lead->id;
        $this->createdLeadId = $lead->id;

        // WHATSAPP_NOTIFY_EVENTS=new_lead. Queued and never throws.
        $this->notifier->newLead($fresh);

        return $lead->id;
    }

    /**
     * Pipeline: the configured one (screen or WHATSAPP_AUTO_LEAD_PIPELINE_ID) or the default
     * one. Stage: by id, else by name (WHATSAPP_AUTO_LEAD_STAGE, env only) inside that
     * pipeline, else its first stage.
     *
     * @param  array|null  $settings  LeadCreationSettings::current(), read when not given.
     * @return array{0: ?Pipeline, 1: ?Stage}
     */
    public function placement(?array $settings = null): array
    {
        $settings ??= LeadCreationSettings::current();

        $pipelineId = $settings['pipeline_id'];
        $pipeline = $pipelineId !== null ? Pipeline::query()->find($pipelineId) : null;

        if ($pipelineId !== null && $pipeline === null) {
            Log::warning('WhatsApp: the automatic lead pipeline was not found, using the default pipeline.', ['pipeline_id' => $pipelineId]);
        }

        $pipeline ??= $this->service->defaultPlacement()[0] ?? null;

        if ($pipeline === null) {
            return [null, null];
        }

        $stages = $pipeline->stages()->orderBy('sort_order')->get();
        $stageId = $settings['stage_id'];
        $stageName = (string) $settings['stage'];
        $stage = null;

        if ($stageId !== null) {
            $stage = $stages->firstWhere('id', $stageId);

            if ($stage === null) {
                Log::warning('WhatsApp: the automatic lead stage is not a stage of the pipeline.', ['stage_id' => $stageId, 'pipeline_id' => $pipeline->id]);
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
