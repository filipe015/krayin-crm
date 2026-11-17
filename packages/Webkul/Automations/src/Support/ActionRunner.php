<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Lead\Models\Lead;

/**
 * The "Então…" part. Each action returns ['status' => ok|skipped|failed, 'detail' => text]; in test
 * mode ($dry) it only says what it would do and changes nothing. Nothing is checked against the
 * screen's permissions here: the rule was validated when saved, and the job runs with no user.
 */
class ActionRunner
{
    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, detail: string}
     */
    public function run(array $action, Lead $lead, AutomationRule $rule, bool $dry): array
    {
        return match ((string) ($action['type'] ?? '')) {
            'move_stage' => $this->moveStage($action, $lead, $dry),
            'transfer_pipeline' => $this->transfer($action, $lead, $rule, $dry),
            'assign_owner' => $this->assignOwner($action, $lead, $dry),
            'add_tag' => $this->addTag($action, $lead, $dry),
            'create_task' => $this->createTask($action, $lead, $rule, $dry),
            'notify_whatsapp' => $this->notifyWhatsApp($action, $lead, $dry),
            'webhook' => $this->webhook($action, $lead, $rule, $dry),
            'mark_lost' => $this->markLost($action, $lead, $rule, $dry),
            default => self::failed('unknown_action'),
        };
    }

    private function moveStage(array $action, Lead $lead, bool $dry): array
    {
        $stage = self::stage($action['stage_id'] ?? null);

        if (! $stage) {
            return self::failed('stage_missing');
        }

        if ((int) $stage->lead_pipeline_id !== (int) $lead->lead_pipeline_id) {
            return self::failed('stage_other_pipeline', ['stage' => $stage->name]);
        }

        if ((int) $lead->lead_pipeline_stage_id === (int) $stage->id) {
            return self::skipped('already_in_stage', ['stage' => $stage->name]);
        }

        if ($dry) {
            return self::ok('would_move', ['stage' => $stage->name]);
        }

        LeadWriter::update($lead, ['lead_pipeline_stage_id' => (int) $stage->id]);

        return self::ok('moved', ['stage' => $stage->name]);
    }

    /**
     * The same lead (same id) goes to another pipeline: history, activities, WhatsApp conversations
     * (linked by lead_id), source and UTM fields stay with it. closed_at is kept: a won lead keeps
     * its closing date (see README: the won reports still count by the CURRENT stage).
     */
    private function transfer(array $action, Lead $lead, AutomationRule $rule, bool $dry): array
    {
        $stage = self::stage($action['stage_id'] ?? null);

        if (! $stage || (int) $stage->lead_pipeline_id !== (int) ($action['pipeline_id'] ?? 0)) {
            return self::failed('stage_missing');
        }

        if ((int) $lead->lead_pipeline_stage_id === (int) $stage->id) {
            return self::skipped('already_in_stage', ['stage' => $stage->name]);
        }

        $replace = [
            'from_pipeline' => (string) DB::table('lead_pipelines')->where('id', $lead->lead_pipeline_id)->value('name'),
            'from_stage' => (string) DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('name'),
            'to_pipeline' => (string) DB::table('lead_pipelines')->where('id', $stage->lead_pipeline_id)->value('name'),
            'to_stage' => (string) $stage->name,
            'rule' => $rule->name,
        ];

        if ($dry) {
            return self::ok('would_transfer', $replace);
        }

        LeadWriter::update($lead, [
            'lead_pipeline_id' => (int) $stage->lead_pipeline_id,
            'lead_pipeline_stage_id' => (int) $stage->id,
        ], keepClosedAt: true);

        $this->note($lead, $rule, trans('automations::app.engine.transfer_note', $replace));

        return self::ok('transferred', $replace);
    }

    private function assignOwner(array $action, Lead $lead, bool $dry): array
    {
        $user = DB::table('users')->where('id', (int) ($action['user_id'] ?? 0))->first(['id', 'name', 'status']);

        if (! $user || ! (int) $user->status) {
            return self::failed('user_inactive');
        }

        if ((int) $lead->user_id === (int) $user->id) {
            return self::skipped('already_owner', ['user' => $user->name]);
        }

        if ($dry) {
            return self::ok('would_assign', ['user' => $user->name]);
        }

        LeadWriter::update($lead, ['user_id' => (int) $user->id]);

        return self::ok('assigned', ['user' => $user->name]);
    }

    private function addTag(array $action, Lead $lead, bool $dry): array
    {
        $tag = DB::table('tags')->where('id', (int) ($action['tag_id'] ?? 0))->first(['id', 'name']);

        if (! $tag) {
            return self::failed('tag_missing');
        }

        if (DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag->id)->exists()) {
            return self::skipped('already_tagged', ['tag' => $tag->name]);
        }

        if ($dry) {
            return self::ok('would_tag', ['tag' => $tag->name]);
        }

        // Through the relation (LeadTag pivot), so "lead.tag.attached" goes out like a manual tag.
        $lead->tags()->attach($tag->id);

        return self::ok('tagged', ['tag' => $tag->name]);
    }

    private function createTask(array $action, Lead $lead, AutomationRule $rule, bool $dry): array
    {
        $days = max(0, min(365, (int) ($action['due_in_days'] ?? 1)));
        $title = Str::limit(Placeholders::render((string) ($action['title'] ?? ''), $lead), 250, '');
        $userId = ($action['assignee'] ?? 'owner') === 'fixed' ? (int) ($action['user_id'] ?? 0) : (int) $lead->user_id;

        if ($userId > 0 && ! DB::table('users')->where('id', $userId)->where('status', 1)->exists()) {
            return self::failed('user_inactive');
        }

        $due = now()->addDays($days)->startOfMinute();
        $replace = ['title' => $title, 'date' => $due->format('d/m/Y H:i')];

        if ($dry) {
            return self::ok('would_create_task', $replace);
        }

        $activity = app(ActivityRepository::class)->create([
            'type' => 'task',
            'title' => $title,
            'comment' => trans('automations::app.engine.actor', ['rule' => $rule->name]),
            'schedule_from' => $due,
            'schedule_to' => $due->copy()->addMinutes(30),
            'is_done' => 0,
            'user_id' => $userId > 0 ? $userId : null,
            'additional' => json_encode(['automation_rule_id' => $rule->id]),
        ]);

        // Through the relation (LeadActivity pivot), so "lead.activity.attached" goes out.
        $lead->activities()->attach($activity->id);

        return self::ok('task_created', $replace);
    }

    private function notifyWhatsApp(array $action, Lead $lead, bool $dry): array
    {
        $notifier = 'Webkul\\WhatsApp\\Support\\AgentNotifier';
        $job = 'Webkul\\WhatsApp\\Jobs\\SendAgentNotification';

        $session = class_exists($notifier) ? $notifier::session() : '';

        if ($session === '' || ! class_exists($job)) {
            Log::info('Automations: WhatsApp notice skipped, WHATSAPP_NOTIFY_SESSION is not configured.', ['lead_id' => $lead->id]);

            return self::skipped('whatsapp_off');
        }

        $chats = self::chatIds((string) ($action['to'] ?? ''));

        if ($chats === []) {
            return self::failed('whatsapp_no_destination');
        }

        $text = Placeholders::render((string) ($action['message'] ?? ''), $lead);
        $replace = ['to' => implode(', ', $chats), 'text' => Str::limit($text, 120, '…')];

        if ($dry) {
            return self::ok('would_notify', $replace);
        }

        foreach ($chats as $chatId) {
            $job::dispatch($session, $chatId, $text);
        }

        return self::ok('notified', $replace);
    }

    private function webhook(array $action, Lead $lead, AutomationRule $rule, bool $dry): array
    {
        $url = (string) ($action['url'] ?? '');

        if (! RuleValidator::safeUrl($url)) {
            return self::failed('webhook_url');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($dry) {
            return self::ok('would_post', ['host' => $host]);
        }

        $timeout = max(1, min(15, (int) config('automations.webhook_timeout', 5)));
        $headers = filled($action['secret'] ?? null) ? ['X-Automation-Secret' => (string) $action['secret']] : [];

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post($url, self::payload($lead, $rule));
        } catch (Throwable $exception) {
            return self::failed('webhook_error', ['host' => $host, 'error' => Str::limit($exception->getMessage(), 200)]);
        }

        if ($response->failed()) {
            return self::failed('webhook_status', ['host' => $host, 'status' => $response->status()]);
        }

        return self::ok('posted', ['host' => $host, 'status' => $response->status()]);
    }

    private function markLost(array $action, Lead $lead, AutomationRule $rule, bool $dry): array
    {
        $stage = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $lead->lead_pipeline_id)->where('code', 'lost')->orderBy('sort_order')->first(['id', 'name']);

        if (! $stage) {
            return self::failed('no_lost_stage');
        }

        if ((int) $lead->lead_pipeline_stage_id === (int) $stage->id) {
            return self::skipped('already_in_stage', ['stage' => $stage->name]);
        }

        $data = ['lead_pipeline_stage_id' => (int) $stage->id];

        if (filled($action['reason_id'] ?? null)) {
            $reason = DB::table('lead_lost_reasons')->where('id', (int) $action['reason_id'])->where('lead_pipeline_id', $lead->lead_pipeline_id)->first(['id', 'name']);

            if ($reason) {
                $data += ['lost_reason' => $reason->name, 'lost_reason_id' => (int) $reason->id];
            }
        }

        $replace = ['stage' => $stage->name, 'reason' => $data['lost_reason'] ?? '—'];

        if ($dry) {
            return self::ok('would_lose', $replace);
        }

        LeadWriter::update($lead, $data);

        return self::ok('lost', $replace);
    }

    /**
     * A note on the lead's timeline. No user is invented: user_id stays empty and the text says
     * which automation wrote it.
     */
    private function note(Lead $lead, AutomationRule $rule, string $text): void
    {
        $activity = app(ActivityRepository::class)->create([
            'type' => 'note',
            'title' => Str::limit($text, 250, '…'),
            'comment' => $text."\n".trans('automations::app.engine.actor', ['rule' => $rule->name]),
            'is_done' => 1,
            'user_id' => null,
            'additional' => json_encode(['automation_rule_id' => $rule->id]),
        ]);

        $lead->activities()->attach($activity->id);
    }

    /**
     * What a webhook receives: the lead's basics, never the whole record.
     *
     * @return array<string, mixed>
     */
    public static function payload(Lead $lead, AutomationRule $rule): array
    {
        $person = $lead->person_id ? DB::table('persons')->where('id', $lead->person_id)->first(['id', 'name', 'emails', 'contact_numbers']) : null;
        $stage = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->first(['id', 'name', 'code']);

        return [
            'event' => 'automation',
            'automation' => ['id' => $rule->id, 'name' => $rule->name],
            'sent_at' => now()->toIso8601String(),
            'lead' => [
                'id' => $lead->id,
                'title' => $lead->title,
                'value' => $lead->lead_value !== null ? (float) $lead->lead_value : null,
                'pipeline' => ['id' => (int) $lead->lead_pipeline_id, 'name' => DB::table('lead_pipelines')->where('id', $lead->lead_pipeline_id)->value('name')],
                'stage' => $stage ? ['id' => (int) $stage->id, 'name' => $stage->name, 'code' => $stage->code] : null,
                'owner' => $lead->user_id ? ['id' => (int) $lead->user_id, 'name' => DB::table('users')->where('id', $lead->user_id)->value('name')] : null,
                'source' => $lead->lead_source_id ? DB::table('lead_sources')->where('id', $lead->lead_source_id)->value('name') : null,
                'tags' => DB::table('lead_tags')->join('tags', 'tags.id', '=', 'lead_tags.tag_id')->where('lead_tags.lead_id', $lead->id)->pluck('tags.name')->all(),
                'person' => $person ? [
                    'id' => (int) $person->id,
                    'name' => $person->name,
                    'emails' => collect(json_decode((string) $person->emails, true) ?: [])->pluck('value')->filter()->values()->all(),
                    'phones' => collect(json_decode((string) $person->contact_numbers, true) ?: [])->pluck('value')->filter()->values()->all(),
                ] : null,
                'created_at' => optional($lead->created_at)->toIso8601String(),
                'url' => Placeholders::link($lead),
            ],
        ];
    }

    /**
     * Digits become 55…@c.us (a Brazilian number without the country code gets 55), anything with
     * "@" (a group, …@g.us) is kept as is. Several destinations separated by commas.
     *
     * @return list<string>
     */
    public static function chatIds(string $to): array
    {
        $chats = [];

        foreach (explode(',', $to) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '@')) {
                $chats[] = $entry;

                continue;
            }

            $digits = (string) preg_replace('/\D/', '', $entry);

            if (strlen($digits) < 10) {
                continue;
            }

            if (strlen($digits) <= 11 && ! str_starts_with($digits, '55')) {
                $digits = '55'.$digits;
            }

            $chats[] = $digits.'@c.us';
        }

        return array_values(array_unique($chats));
    }

    private static function stage(mixed $id): ?object
    {
        return $id ? DB::table('lead_pipeline_stages')->where('id', (int) $id)->first(['id', 'name', 'code', 'lead_pipeline_id']) : null;
    }

    private static function ok(string $key, array $replace = []): array
    {
        return ['status' => 'ok', 'detail' => trans('automations::app.engine.'.$key, $replace)];
    }

    private static function skipped(string $key, array $replace = []): array
    {
        return ['status' => 'skipped', 'detail' => trans('automations::app.engine.'.$key, $replace)];
    }

    private static function failed(string $key, array $replace = []): array
    {
        return ['status' => 'failed', 'detail' => trans('automations::app.engine.'.$key, $replace)];
    }
}
