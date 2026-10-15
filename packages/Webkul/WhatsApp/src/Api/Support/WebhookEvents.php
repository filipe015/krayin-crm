<?php

namespace Webkul\WhatsApp\Api\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Activity\Models\Activity;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Api\Models\ApiWebhook;

/**
 * Outgoing webhooks. The model events (a lead was created, changed stage, an appointment was booked)
 * are noted during the request and delivered after the response is sent, so the user never waits for
 * a slow integration and the lead is complete (person, custom fields, activity link) when described.
 *
 * Each delivery is a JSON POST with:
 *   X-CRM-Event      event name
 *   X-CRM-Delivery   unique id of this delivery
 *   X-CRM-Timestamp  unix time
 *   X-CRM-Signature  sha256=HMAC_SHA256("{timestamp}.{body}", secret)
 */
final class WebhookEvents
{
    public const EVENTS = [
        'lead.created',
        'lead.stage_changed',
        'lead.won',
        'lead.lost',
        'appointment.created',
        'appointment.done',
        'followup.created',
    ];

    /** @var list<array{event: string, context: array<string, mixed>}> */
    private static array $pending = [];

    private static bool $scheduled = false;

    private static ?bool $hasWebhooks = null;

    /** Bulk imports ask for silence: nothing recorded in this request reaches the webhooks. */
    private static bool $muted = false;

    /** @var array<int, int|null> */
    private static array $stageBefore = [];

    public static function register(): void
    {
        Lead::created(function (Lead $lead) {
            self::record('lead.created', ['lead_id' => $lead->id]);
        });

        Lead::updating(function (Lead $lead) {
            if ($lead->isDirty('lead_pipeline_stage_id')) {
                self::$stageBefore[$lead->id] = $lead->getOriginal('lead_pipeline_stage_id');
            }
        });

        Lead::updated(function (Lead $lead) {
            if (! $lead->wasChanged('lead_pipeline_stage_id')) {
                return;
            }

            $context = ['lead_id' => $lead->id, 'from_stage_id' => self::$stageBefore[$lead->id] ?? null];

            self::record('lead.stage_changed', $context);

            $code = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code');

            if (in_array($code, ['won', 'lost'], true)) {
                self::record('lead.'.$code, $context);
            }
        });

        Activity::created(function (Activity $activity) {
            $kind = self::kindOf($activity);

            if ($kind) {
                self::record($kind.'.created', ['activity_id' => $activity->id]);
            }
        });

        Activity::updated(function (Activity $activity) {
            if ($activity->wasChanged('is_done') && $activity->is_done && self::kindOf($activity)) {
                self::record('appointment.done', ['activity_id' => $activity->id]);
            }
        });
    }

    /**
     * "appointment" (a meeting), "followup" (a call marked as follow-up) or null (anything else).
     */
    private static function kindOf(Activity $activity): ?string
    {
        if ($activity->type === 'meeting') {
            return 'appointment';
        }

        if ($activity->type === 'call') {
            $additional = is_array($activity->additional) ? $activity->additional : json_decode((string) $activity->additional, true);

            return ($additional['kind'] ?? null) === 'followup' ? 'followup' : null;
        }

        return null;
    }

    /**
     * Forgets what was noted in this process (a long-lived worker, or the test suite).
     */
    public static function reset(): void
    {
        self::$pending = [];
        self::$scheduled = false;
        self::$hasWebhooks = null;
        self::$muted = false;
        self::$stageBefore = [];
    }

    public static function mute(bool $muted = true): void
    {
        self::$muted = $muted;
    }

    public static function record(string $event, array $context): void
    {
        if (self::$muted || ! self::anyWebhook()) {
            return;
        }

        self::$pending[] = ['event' => $event, 'context' => $context];

        if (! self::$scheduled) {
            self::$scheduled = true;

            app()->terminating(fn () => self::flush());
        }
    }

    private static function anyWebhook(): bool
    {
        if (self::$hasWebhooks === null) {
            try {
                self::$hasWebhooks = ApiWebhook::query()->where('is_active', true)->exists();
            } catch (Throwable) {
                self::$hasWebhooks = false;
            }
        }

        return self::$hasWebhooks;
    }

    public static function flush(): void
    {
        $pending = self::$pending;

        self::$pending = [];
        self::$scheduled = false;

        if ($pending === []) {
            return;
        }

        $webhooks = ApiWebhook::query()->where('is_active', true)->get();

        foreach ($pending as $item) {
            $targets = $webhooks->filter(fn (ApiWebhook $webhook) => $webhook->listensTo($item['event']));

            if ($targets->isEmpty()) {
                continue;
            }

            try {
                $data = self::describe($item['event'], $item['context']);
            } catch (Throwable) {
                continue;
            }

            if ($data === null) {
                continue;
            }

            foreach ($targets as $webhook) {
                self::deliver($webhook, $item['event'], $data);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function describe(string $event, array $context): ?array
    {
        $data = [];

        if (isset($context['activity_id'])) {
            $activity = DB::table('activities')->where('id', $context['activity_id'])->first();

            if (! $activity) {
                return null;
            }

            $context['lead_id'] = DB::table('lead_activities')->where('activity_id', $activity->id)->value('lead_id');

            $data['appointment'] = [
                'id'       => $activity->id,
                'kind'     => $activity->type === 'meeting' ? 'appointment' : 'followup',
                'title'    => $activity->title,
                'comment'  => $activity->comment,
                'location' => $activity->location,
                'start'    => $activity->schedule_from ? Carbon::parse($activity->schedule_from)->toIso8601String() : null,
                'end'      => $activity->schedule_to ? Carbon::parse($activity->schedule_to)->toIso8601String() : null,
                'is_done'  => (bool) $activity->is_done,
                'user_id'  => $activity->user_id,
            ];
        }

        $lead = ! empty($context['lead_id']) ? Lead::query()->find($context['lead_id']) : null;

        if ($lead) {
            $data['lead'] = LeadPresenter::make($lead);
        }

        if (array_key_exists('from_stage_id', $context)) {
            $from = $context['from_stage_id']
                ? DB::table('lead_pipeline_stages')->where('id', $context['from_stage_id'])->first(['id', 'name', 'code'])
                : null;

            $data['from_stage'] = $from ? ['id' => $from->id, 'name' => $from->name, 'code' => $from->code] : null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: int|null, error: string|null}
     */
    public static function deliver(ApiWebhook $webhook, string $event, array $data): array
    {
        $delivery = (string) Str::uuid();
        $timestamp = time();

        $body = json_encode([
            'id'          => $delivery,
            'event'       => $event,
            'occurred_at' => now()->toIso8601String(),
            'source'      => config('app.name'),
            'data'        => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $webhook->secret);

        $status = null;
        $error = null;

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'X-CRM-Event'     => $event,
                    'X-CRM-Delivery'  => $delivery,
                    'X-CRM-Timestamp' => (string) $timestamp,
                    'X-CRM-Signature' => $signature,
                    'User-Agent'      => 'Krayin-CRM-Webhook/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            $status = $response->status();

            if (! $response->successful()) {
                $error = 'HTTP '.$status;
            }
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 200);
        }

        $webhook->forceFill([
            'last_status'  => $status,
            'last_error'   => $error,
            'last_sent_at' => now(),
            'failures'     => $error ? $webhook->failures + 1 : 0,
        ])->save();

        return ['status' => $status, 'error' => $error];
    }
}
