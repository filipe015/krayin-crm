<?php

namespace Webkul\Lead\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Lead\Models\Lead;

/**
 * Muda funil/etapa de um lead do mesmo jeito que o Kanban e a página do lead: os eventos
 * "lead.update.before/after" saem (workflows nativos, webhooks da API), os eventos do model disparam
 * (lead_events das Metas, Regras de funil, gatilho "entrou na etapa" das Automações), "stage_entered_at"
 * só é carimbado numa mudança real de etapa e "closed_at" segue o LeadRepository::update — preenchido
 * em ganho/perdido, limpo numa etapa aberta — a não ser que $keepClosedAt peça para manter (transferência
 * de um lead já ganho feita pela automação).
 *
 * Compartilhado pela ação "Transferir para outro funil" das Automações e pela troca rápida de
 * funil/etapa do Kanban e da página do lead. O mesmo lead (mesmo id) muda de lugar: atividades,
 * conversas do WhatsApp, origem e UTMs continuam com ele.
 */
final class PipelineTransfer
{
    /**
     * Grava $data no lead disparando os mesmos eventos de uma edição normal.
     *
     * @param  array<string, mixed>  $data
     */
    public static function write(Lead $lead, array $data, bool $keepClosedAt = false): Lead
    {
        if (array_key_exists('lead_pipeline_stage_id', $data) && (int) $data['lead_pipeline_stage_id'] !== (int) $lead->lead_pipeline_stage_id) {
            $code = DB::table('lead_pipeline_stages')->where('id', $data['lead_pipeline_stage_id'])->value('code');

            $data['stage_entered_at'] = now();

            if (in_array($code, ['won', 'lost'], true)) {
                $data['closed_at'] = $data['closed_at'] ?? now();
            } elseif (! $keepClosedAt) {
                $data['closed_at'] = null;
            }
        }

        Event::dispatch('lead.update.before', $lead->id);

        $lead->forceFill($data)->save();

        Event::dispatch('lead.update.after', $lead);

        return $lead;
    }

    /**
     * De onde para onde o lead vai: nomes e códigos do funil/etapa atuais e de destino.
     *
     * @return array{from_pipeline_id: int, from_pipeline: string, from_stage_id: int, from_stage: string, from_code: ?string, to_pipeline_id: int, to_pipeline: string, to_stage_id: int, to_stage: string, to_code: ?string, pipeline_changed: bool, stage_changed: bool}
     */
    public static function describe(Lead $lead, object $stage): array
    {
        $from = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->first(['id', 'name', 'code']);

        $pipelines = DB::table('lead_pipelines')
            ->whereIn('id', [(int) $lead->lead_pipeline_id, (int) $stage->lead_pipeline_id])
            ->pluck('name', 'id');

        return [
            'from_pipeline_id' => (int) $lead->lead_pipeline_id,
            'from_pipeline' => (string) ($pipelines[(int) $lead->lead_pipeline_id] ?? ''),
            'from_stage_id' => (int) $lead->lead_pipeline_stage_id,
            'from_stage' => (string) ($from->name ?? ''),
            'from_code' => $from->code ?? null,
            'to_pipeline_id' => (int) $stage->lead_pipeline_id,
            'to_pipeline' => (string) ($pipelines[(int) $stage->lead_pipeline_id] ?? ''),
            'to_stage_id' => (int) $stage->id,
            'to_stage' => (string) $stage->name,
            'to_code' => $stage->code ?? null,
            'pipeline_changed' => (int) $lead->lead_pipeline_id !== (int) $stage->lead_pipeline_id,
            'stage_changed' => (int) $lead->lead_pipeline_stage_id !== (int) $stage->id,
        ];
    }

    /**
     * Leva o lead para a etapa $stage (e para o funil dela). Devolve o describe() feito antes da mudança.
     *
     * @param  object  $stage  precisa de id, name, code e lead_pipeline_id
     * @param  array<string, mixed>  $extra  campos gravados junto (ex.: motivo de perda)
     * @return array<string, mixed>
     */
    public static function move(Lead $lead, object $stage, array $extra = [], bool $keepClosedAt = false): array
    {
        $move = self::describe($lead, $stage);

        self::write($lead, $extra + [
            'lead_pipeline_id' => (int) $stage->lead_pipeline_id,
            'lead_pipeline_stage_id' => (int) $stage->id,
        ], $keepClosedAt);

        return $move;
    }
}
