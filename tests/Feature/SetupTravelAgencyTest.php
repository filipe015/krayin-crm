<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Models\AgendaSetting;
use Webkul\WhatsApp\Models\FunnelRule;

uses(DatabaseTransactions::class);

it('sets up a travel agency and does not duplicate anything when run again', function () {
    $this->artisan('agency:setup-travel')->assertSuccessful();

    $sales = DB::table('lead_pipelines')->where('name', 'Vendas de viagens')->first();
    $post = DB::table('lead_pipelines')->where('name', 'Pós-venda')->first();

    expect($sales->type)->toBe('commercial')->and((bool) $sales->is_default)->toBeTrue()
        ->and($post->type)->toBe('post_sale')->and((bool) $post->counts_in_reports)->toBeFalse()
        ->and(DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $sales->id)->whereIn('code', ['won', 'lost'])->count())->toBe(2)
        ->and(DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $post->id)->whereIn('code', ['won', 'lost'])->count())->toBe(2)
        ->and(DB::table('attributes')->where('entity_type', 'leads')->whereIn('code', ['destino', 'data_ida', 'orcamento_estimado', 'utm_campaign', 'tipo_viagem'])->count())->toBe(5)
        ->and(DB::table('attribute_options')->where('attribute_id', DB::table('attributes')->where('code', 'tipo_viagem')->value('id'))->count())->toBe(7)
        ->and(DB::table('products')->where('sku', 'like', 'SRV-%')->count())->toBe(6)
        ->and(AgendaSetting::current()->singular)->toBe('Reunião')
        ->and(FunnelRule::query()->where('source_pipeline_id', $sales->id)->where('target_pipeline_id', $post->id)->count())->toBe(1);

    $counts = fn () => [
        DB::table('lead_pipelines')->count(), DB::table('lead_pipeline_stages')->count(), DB::table('attributes')->count(),
        DB::table('products')->count(), DB::table('lead_sources')->count(), FunnelRule::query()->count(), DB::table('whatsapp_agenda_settings')->count(),
    ];

    $before = $counts();

    $this->artisan('agency:setup-travel')->assertSuccessful();

    expect($counts())->toBe($before);
});
