<?php

namespace Webkul\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Admin\Support\DefaultRoles;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Lead\Models\Pipeline;
use Webkul\WhatsApp\Models\AgendaSetting;
use Webkul\WhatsApp\Models\FunnelRule;

/**
 * The starting point of a travel agency: the sales and post-sale pipelines, the fields of a trip, the
 * services, the Agenda wording, the passage rule (sale won -> post-sale card) and the standard profiles.
 * Safe to run again: whatever exists (matched by name, code or sku) is left as it is.
 */
class SetupTravelAgency extends Command
{
    protected $signature = 'agency:setup-travel {--clean-empty : Also remove the other pipelines that have no deals (the installer sample one)}';

    protected $description = 'Creates the pipelines, fields, services, Agenda wording and profiles for a travel agency';

    private const SALES = 'Vendas de viagens';

    private const POST_SALE = 'Pós-venda';

    public function handle(AttributeRepository $attributes): int
    {
        $sales = $this->pipeline(self::SALES, 'commercial', true, 30, [
            ['Novo lead', 'new', 10, 1], ['Qualificação', 'qualification', 20, 2], ['Cotação enviada', 'quoted', 40, 3],
            ['Negociação', 'negotiation', 60, 4], ['Aguardando pagamento', 'awaiting_payment', 80, 5],
            ['Fechado', 'won', 100, 98], ['Perdido', 'lost', 0, 99],
        ]);

        $post = $this->pipeline(self::POST_SALE, 'post_sale', false, 60, [
            ['Documentação', 'documents', 10, 1], ['Pronto para viajar', 'ready', 40, 2], ['Em viagem', 'traveling', 70, 3],
            ['Pós-viagem', 'after_trip', 90, 4], ['Concluído', 'won', 100, 98], ['Cancelado', 'lost', 0, 99],
        ]);

        DB::table('lead_pipelines')->where('id', '!=', $sales)->update(['is_default' => 0]);
        DB::table('lead_pipelines')->where('id', $sales)->update(['is_default' => 1]);

        if ($this->option('clean-empty')) {
            $this->cleanEmptyPipelines([$sales, $post]);
        }

        $this->sources();
        $this->fields($attributes);
        $this->services();
        $this->agenda($sales);
        $this->passageRule($sales, $post);

        $roles = DefaultRoles::install();
        $this->info('Funções criadas: '.($roles['created'] ? implode(', ', $roles['created']) : '—'));

        Pipeline::forgetCounted();

        $this->info('Pronto. Abra o CRM e confira os funis, os campos e a Agenda.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $stages  name, code, probability, sort order
     */
    private function pipeline(string $name, string $type, bool $counts, int $rottenDays, array $stages): int
    {
        $existing = DB::table('lead_pipelines')->where('name', $name)->value('id');

        if ($existing) {
            $this->line("Funil já existia: {$name}");

            return (int) $existing;
        }

        $id = DB::table('lead_pipelines')->insertGetId([
            'name' => $name, 'type' => $type, 'counts_in_reports' => $counts, 'rotten_days' => $rottenDays, 'is_default' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($stages as [$stageName, $code, $probability, $order]) {
            DB::table('lead_pipeline_stages')->insert([
                'name' => $stageName, 'code' => $code, 'probability' => $probability, 'sort_order' => $order, 'lead_pipeline_id' => $id,
            ]);
        }

        $this->info("Funil criado: {$name}");

        return (int) $id;
    }

    /**
     * @param  list<int>  $keep
     */
    private function cleanEmptyPipelines(array $keep): void
    {
        $empty = DB::table('lead_pipelines')->whereNotIn('id', $keep)
            ->whereNotIn('id', DB::table('leads')->select('lead_pipeline_id'))->pluck('name', 'id');

        foreach ($empty as $id => $name) {
            DB::table('lead_pipelines')->where('id', $id)->delete();
            $this->line("Funil vazio removido: {$name}");
        }
    }

    private function sources(): void
    {
        foreach (['WhatsApp', 'Instagram', 'Anúncio Meta', 'Google', 'Site', 'Indicação', 'Orgânico', 'Evento'] as $name) {
            if (! DB::table('lead_sources')->where('name', $name)->exists()) {
                DB::table('lead_sources')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function fields(AttributeRepository $attributes): void
    {
        $fields = [
            ['destino', 'Destino', 'text'], ['data_ida', 'Data de ida', 'date'], ['data_volta', 'Data de volta', 'date'],
            ['adultos', 'Adultos', 'text'], ['criancas', 'Crianças', 'text'], ['orcamento_estimado', 'Orçamento estimado', 'price'],
            ['cidade_saida', 'Cidade de saída', 'text'],
            ['tipo_viagem', 'Tipo de viagem', 'select', ['Lazer', 'Lua de mel', 'Família', 'Corporativo', 'Grupo', 'Intercâmbio', 'Outro']],
            ['utm_source', 'UTM Source', 'text'], ['utm_medium', 'UTM Medium', 'text'], ['utm_campaign', 'UTM Campaign', 'text'],
            ['utm_content', 'UTM Content', 'text'], ['utm_term', 'UTM Term', 'text'],
        ];

        foreach ($fields as $field) {
            [$code, $name, $type] = $field;

            if (DB::table('attributes')->where('entity_type', 'leads')->where('code', $code)->exists()) {
                continue;
            }

            $attribute = $attributes->create([
                'code' => $code, 'name' => $name, 'type' => $type, 'entity_type' => 'leads',
                'is_required' => 0, 'is_unique' => 0, 'quick_add' => 0, 'is_user_defined' => 1,
                'options' => array_map(fn ($option) => ['name' => $option], $field[3] ?? []),
            ]);

            DB::table('attributes')->where('id', $attribute->id)->update([
                'sort_order' => ((int) DB::table('attributes')->where('entity_type', 'leads')->max('sort_order')) + 1,
            ]);

            $this->line("Campo criado: {$name}");
        }
    }

    private function services(): void
    {
        foreach (['Passagem aérea', 'Hospedagem', 'Pacote de viagem', 'Seguro viagem', 'Passeio', 'Transfer'] as $name) {
            $sku = 'SRV-'.Str::upper(Str::slug($name));

            if (! DB::table('products')->where('sku', $sku)->exists()) {
                DB::table('products')->insert(['sku' => $sku, 'name' => $name, 'quantity' => 0, 'price' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Wording and a follow-up prompt when a quote is sent. Only when nothing was saved yet.
     */
    private function agenda(int $salesPipelineId): void
    {
        if (AgendaSetting::query()->exists()) {
            $this->line('Agenda já configurada: mantida.');

            return;
        }

        $quoted = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $salesPipelineId)->where('code', 'quoted')->value('id');

        AgendaSetting::query()->create([
            'singular' => 'Reunião', 'plural' => 'Reuniões', 'gender' => 'f', 'responsible' => 'Consultor', 'responsible_plural' => 'Consultores',
            'default_duration' => 30, 'day_start' => 8, 'day_end' => 20, 'distribution_mode' => 'manual',
            'stage_triggers' => $quoted ? [['pipeline_id' => $salesPipelineId, 'stage_id' => (int) $quoted, 'kind' => 'followup']] : [],
        ]);

        $this->info('Agenda configurada (Reunião / Consultor).');
    }

    private function passageRule(int $salesId, int $postId): void
    {
        if (FunnelRule::query()->where('source_pipeline_id', $salesId)->where('target_pipeline_id', $postId)->exists()) {
            return;
        }

        $first = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $postId)->orderBy('sort_order')->value('id');

        FunnelRule::query()->create([
            'name' => 'Venda fechada → Pós-venda', 'is_active' => true, 'source_pipeline_id' => $salesId, 'target_pipeline_id' => $postId,
            'target_stage_id' => $first, 'assignment_mode' => 'owner', 'copy_value' => false, 'move_conversation' => true,
        ]);

        $this->info('Regra criada: venda fechada abre card no Pós-venda.');
    }
}
