<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/**
 * "Novo negócio" card: the service chosen on it is tied to the deal as the deal's product.
 */
it('saves the deal with the service chosen on the card', function () {
    $service = DB::table('products')->insertGetId(['name' => 'ZZ Serviço teste', 'sku' => 'zz-svc-'.uniqid(), 'quantity' => 0, 'price' => 1500, 'created_at' => now(), 'updated_at' => now()]);
    $stage = DB::table('lead_pipeline_stages')->whereNotIn('code', ['won', 'lost'])->orderBy('id')->first();

    $response = test()->actingAs(getDefaultAdmin())->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->postJson(route('admin.leads.store'), [
        'title'                  => 'ZZ negócio com serviço',
        'lead_value'             => 1500,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stage->id,
        'person'                 => ['name' => 'ZZ Pessoa', 'emails' => [['value' => 'zz-'.uniqid().'@example.test', 'label' => 'work']], 'contact_numbers' => [['value' => '119'.random_int(10000000, 99999999), 'label' => 'work']]],
        'products'               => [['product_id' => $service, 'name' => 'ZZ Serviço teste', 'price' => 1500, 'quantity' => 1]],
    ]);

    $response->assertOk();

    $leadId = $response->json('data.id');

    expect(DB::table('lead_products')->where('lead_id', $leadId)->where('product_id', $service)->count())->toBe(1)
        ->and((float) DB::table('lead_products')->where('lead_id', $leadId)->value('amount'))->toBe(1500.0);
});

it('lists the services on the card only for those who may see them', function () {
    DB::table('products')->insert(['name' => 'ZZ Servico visivel', 'sku' => 'zz-vis-'.uniqid(), 'quantity' => 0, 'price' => 99, 'created_at' => now(), 'updated_at' => now()]);

    $pipeline = DB::table('lead_pipelines')->value('id');

    test()->actingAs(getDefaultAdmin())->get(route('admin.leads.index', ['pipeline_id' => $pipeline]))->assertOk()->assertSee('ZZ Servico visivel', false);
});
