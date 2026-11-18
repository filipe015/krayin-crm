<?php

namespace Webkul\Automations\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Automations\Jobs\RunAutomationRule;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Lead\Models\Lead;

/**
 * Fase 2: gatilhos de tempo, avaliados a cada minuto por automations:tick.
 *
 *  - stage_stale ("Lead parado na etapa há X"): leads.stage_entered_at <= agora − X, numa etapa aberta
 *    (nunca ganho/perdido), filtrada por funil/etapa se a regra pedir. Âncora = stage_entered_at: dispara
 *    UMA vez por permanência na etapa; reentrar na etapa grava um novo stage_entered_at e permite um novo
 *    disparo. Leads com stage_entered_at vazio (anteriores à coluna) são ignorados.
 *  - no_activity ("Lead sem atividade/contato há X"): o último contato é a atividade mais recente ligada
 *    ao lead (lead_activities → activities, exceto "system" e "task": tarefa é trabalho planejado, e a
 *    própria automação cria tarefas) ou a mensagem de WhatsApp mais recente (whatsapp_conversations →
 *    whatsapp_messages, entrada ou saída), ou a criação do lead. Dispara UMA vez por "silêncio": um
 *    novo contato depois do disparo abre um novo período.
 *
 * A memória dos disparos fica em automation_time_marks (único por regra x lead x âncora x modo Teste),
 * consultada com NOT EXISTS na própria busca: um lead que já disparou não ocupa o lote do minuto
 * seguinte. Cada regra dispara no máximo AUTOMATIONS_TIME_TRIGGER_BATCH leads por minuto.
 */
final class TimeTriggers
{
    /** Activity types that do NOT count as contact. */
    public const NOT_CONTACT = ['system', 'task'];

    /**
     * Fires every time-triggered rule (on or test) on the leads that are due. Returns how many jobs went out.
     */
    public static function run(?int $batch = null): int
    {
        $batch = max(1, min(5000, $batch ?? (int) config('automations.time_trigger_batch', 200)));
        $fired = 0;

        $rules = AutomationRule::query()
            ->whereIn('trigger_type', AutomationRule::TIME_TRIGGERS)
            ->whereIn('status', [AutomationRule::STATUS_ON, AutomationRule::STATUS_TEST])
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            try {
                $fired += self::fireRule($rule, $batch);
            } catch (Throwable $exception) {
                Log::warning('Automations: time trigger failed.', ['rule_id' => $rule->id, 'error' => $exception->getMessage()]);
            }
        }

        return $fired;
    }

    private static function fireRule(AutomationRule $rule, int $batch): int
    {
        $dry = $rule->isTest();
        $fired = 0;

        foreach (self::candidates($rule, $batch, $dry) as $candidate) {
            $anchor = $candidate['anchor'];

            try {
                $markId = DB::table('automation_time_marks')->insertGetId([
                    'rule_id' => $rule->id, 'lead_id' => $candidate['lead_id'], 'anchor_at' => $anchor,
                    'dry' => $dry, 'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $context = ['anchor' => $anchor, 'stage_id' => $candidate['stage_id']];

            try {
                $pending = RunAutomationRule::dispatch($rule->id, $candidate['lead_id'], $rule->trigger_type, $context, 0, []);

                if ($queue = config('automations.queue')) {
                    $pending->onQueue($queue);
                }

                unset($pending);
                $fired++;
            } catch (Throwable $exception) {
                // Not queued: forget the mark so the next minute tries again.
                DB::table('automation_time_marks')->where('id', $markId)->delete();
                Log::warning('Automations: time trigger job could not be queued.', ['rule_id' => $rule->id, 'error' => $exception->getMessage()]);
            }
        }

        return $fired;
    }

    /**
     * Leads due for a rule, oldest first, at most $limit, without the ones that already fired.
     *
     * @return Collection<int, array{lead_id: int, anchor: string, stage_id: int|null}>
     */
    public static function candidates(AutomationRule $rule, int $limit, bool $dry): Collection
    {
        $config = (array) $rule->trigger_config;
        $seconds = FlowGraph::seconds($config);

        if ($seconds === 0) {
            return collect();
        }

        $threshold = now()->subSeconds($seconds);

        $query = DB::table('leads')
            ->select('leads.id', 'leads.created_at', 'leads.stage_entered_at', 'leads.lead_pipeline_stage_id')
            ->whereNotNull('leads.lead_pipeline_stage_id')
            ->whereNotIn('leads.lead_pipeline_stage_id', self::closedStageIds());

        if (filled($config['stage_id'] ?? null)) {
            $query->where('leads.lead_pipeline_stage_id', (int) $config['stage_id']);
        } elseif (filled($config['pipeline_id'] ?? null)) {
            $query->where('leads.lead_pipeline_id', (int) $config['pipeline_id']);
        }

        if ($rule->trigger_type === AutomationRule::TRIGGER_STAGE_STALE) {
            $rows = $query
                ->whereNotNull('leads.stage_entered_at')
                ->where('leads.stage_entered_at', '<=', $threshold)
                ->whereNotExists(fn (Builder $marks) => $marks->selectRaw('1')->from('automation_time_marks as m')
                    ->where('m.rule_id', $rule->id)->where('m.dry', $dry)
                    ->whereColumn('m.lead_id', 'leads.id')
                    ->whereColumn('m.anchor_at', 'leads.stage_entered_at'))
                ->orderBy('leads.stage_entered_at')
                ->limit($limit)
                ->get();

            return $rows->map(fn ($row) => [
                'lead_id' => (int) $row->id,
                'anchor' => Carbon::parse($row->stage_entered_at)->toDateTimeString(),
                'stage_id' => (int) $row->lead_pipeline_stage_id,
            ])->values();
        }

        $rows = $query
            ->where('leads.created_at', '<=', $threshold)
            ->whereNotExists(fn (Builder $q) => self::contactAfter($q, 'leads.id', $threshold))
            ->when(self::hasWhatsApp(), fn ($q) => $q->whereNotExists(fn (Builder $q) => self::messageAfter($q, 'leads.id', $threshold)))
            // Already fired for this silence: a mark with no contact after it.
            ->whereNotExists(function (Builder $marks) use ($rule, $dry) {
                $marks->selectRaw('1')->from('automation_time_marks as m')
                    ->where('m.rule_id', $rule->id)->where('m.dry', $dry)
                    ->whereColumn('m.lead_id', 'leads.id')
                    ->whereNotExists(fn (Builder $q) => self::contactAfter($q, 'm.lead_id', 'm.created_at'))
                    ->when(self::hasWhatsApp(), fn ($q) => $q->whereNotExists(fn (Builder $q) => self::messageAfter($q, 'm.lead_id', 'm.created_at')));
            })
            ->orderBy('leads.id')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'lead_id' => (int) $row->id,
            'anchor' => (self::lastContact((int) $row->id) ?? Carbon::parse($row->created_at))->toDateTimeString(),
            'stage_id' => (int) $row->lead_pipeline_stage_id,
        ])->values();
    }

    /**
     * Right before running (the job may run seconds after the tick): does the lead still qualify?
     *
     * @param  array<string, mixed>  $context
     */
    public static function stillQualifies(AutomationRule $rule, Lead $lead, array $context): bool
    {
        if (in_array((int) $lead->lead_pipeline_stage_id, self::closedStageIds(), true)) {
            return false;
        }

        if ($rule->trigger_type === AutomationRule::TRIGGER_STAGE_STALE) {
            return (int) $lead->lead_pipeline_stage_id === (int) ($context['stage_id'] ?? 0)
                && $lead->stage_entered_at
                && $lead->stage_entered_at->toDateTimeString() === (string) ($context['anchor'] ?? '');
        }

        $seconds = FlowGraph::seconds((array) $rule->trigger_config);
        $last = self::lastContact((int) $lead->id) ?? $lead->created_at;

        return $seconds > 0 && $last && $last->lte(now()->subSeconds($seconds));
    }

    /**
     * For "Simular": whether the lead qualifies now, in words (the walk goes on either way).
     *
     * @return array{status: string, detail: string}
     */
    public static function describe(AutomationRule $rule, Lead $lead): array
    {
        $config = (array) $rule->trigger_config;
        $after = self::human($config);

        if ($rule->trigger_type === AutomationRule::TRIGGER_STAGE_STALE) {
            if (! $lead->stage_entered_at) {
                return ['status' => 'skipped', 'detail' => trans('automations::app.engine.stale_unknown')];
            }

            $since = $lead->stage_entered_at;
        } else {
            $since = self::lastContact((int) $lead->id) ?? $lead->created_at;
        }

        $seconds = FlowGraph::seconds($config);
        $due = $since && $seconds > 0 && $since->lte(now()->subSeconds($seconds));
        $key = $rule->trigger_type === AutomationRule::TRIGGER_STAGE_STALE ? 'stale_since' : 'contact_since';

        return [
            'status' => $due ? 'ok' : 'skipped',
            'detail' => trans('automations::app.engine.'.$key, [
                'date' => $since?->format('d/m/Y H:i') ?? '—',
                'after' => $after,
            ]).' '.trans('automations::app.engine.'.($due ? 'time_due' : 'time_not_due')),
        ];
    }

    public static function lastContact(int $leadId): ?Carbon
    {
        $activity = DB::table('lead_activities')
            ->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
            ->where('lead_activities.lead_id', $leadId)
            ->whereNotIn('activities.type', self::NOT_CONTACT)
            ->max('activities.created_at');

        $message = self::hasWhatsApp()
            ? DB::table('whatsapp_messages')
                ->join('whatsapp_conversations', 'whatsapp_conversations.id', '=', 'whatsapp_messages.conversation_id')
                ->where('whatsapp_conversations.lead_id', $leadId)
                ->max('whatsapp_messages.created_at')
            : null;

        $dates = array_filter([$activity, $message]);

        return $dates === [] ? null : Carbon::parse(max($dates));
    }

    /** "3 dias", "24 horas"… */
    public static function human(array $config): string
    {
        $unit = array_key_exists((string) ($config['unit'] ?? ''), FlowGraph::UNITS) ? (string) $config['unit'] : 'hours';
        $amount = (int) ($config['amount'] ?? 0);

        return trans_choice('automations::app.units.'.$unit, $amount, ['count' => $amount]);
    }

    /** @return list<int> */
    public static function closedStageIds(): array
    {
        return DB::table('lead_pipeline_stages')->whereIn('code', ['won', 'lost'])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private static function contactAfter(Builder $query, string $leadColumn, mixed $after): void
    {
        $query->selectRaw('1')->from('lead_activities as la')
            ->join('activities as a', 'a.id', '=', 'la.activity_id')
            ->whereColumn('la.lead_id', $leadColumn)
            ->whereNotIn('a.type', self::NOT_CONTACT);

        is_string($after) ? $query->whereColumn('a.created_at', '>', $after) : $query->where('a.created_at', '>', $after);
    }

    private static function messageAfter(Builder $query, string $leadColumn, mixed $after): void
    {
        $query->selectRaw('1')->from('whatsapp_messages as wm')
            ->join('whatsapp_conversations as wc', 'wc.id', '=', 'wm.conversation_id')
            ->whereColumn('wc.lead_id', $leadColumn);

        is_string($after) ? $query->whereColumn('wm.created_at', '>', $after) : $query->where('wm.created_at', '>', $after);
    }

    private static function hasWhatsApp(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('whatsapp_messages') && Schema::hasTable('whatsapp_conversations');
    }
}
