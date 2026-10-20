<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\WhatsApp\Models\FunnelRule;

uses(DatabaseTransactions::class);

/**
 * A deal won in a pipeline opens a card in another one, as the rules say.
 */
function rulePipeline(string $name): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ '.$name.' '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $stage = fn (string $label, string $code, int $order) => DB::table('lead_pipeline_stages')->insertGetId(['name' => $label, 'code' => $code, 'probability' => 10, 'sort_order' => $order, 'lead_pipeline_id' => $pipelineId]);

    Pipeline::forgetCounted();

    return ['pipeline' => $pipelineId, 'open' => $stage('Novo', 'new', 1), 'won' => $stage('Ganho', 'won', 98), 'lost' => $stage('Perdido', 'lost', 99)];
}

function ruleUser(): int
{
    return DB::table('users')->insertGetId([
        'name' => 'ZZ Pessoa', 'email' => 'zz-'.uniqid().'@example.test', 'password' => 'x', 'status' => 1,
        'role_id' => DB::table('roles')->value('id'), 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ruleFor(array $source, array $target, array $extra = []): FunnelRule
{
    return FunnelRule::query()->create($extra + [
        'name' => 'ZZ regra', 'is_active' => true, 'source_pipeline_id' => $source['pipeline'],
        'target_pipeline_id' => $target['pipeline'], 'target_stage_id' => $target['open'], 'assignment_mode' => 'owner',
        'copy_value' => false, 'move_conversation' => true,
    ]);
}

function ruleDeal(array $pipeline, ?int $owner = null): Lead
{
    $id = DB::table('leads')->insertGetId([
        'title' => 'ZZ venda', 'description' => 'obs', 'lead_value' => 1500, 'status' => 1, 'user_id' => $owner,
        'lead_pipeline_id' => $pipeline['pipeline'], 'lead_pipeline_stage_id' => $pipeline['open'], 'created_at' => now(), 'updated_at' => now(),
    ]);

    return Lead::query()->withoutGlobalScopes()->findOrFail($id);
}

function winDeal(Lead $lead, array $pipeline): void
{
    $lead->update(['lead_pipeline_stage_id' => $pipeline['won'], 'closed_at' => now()]);
}

function cardsOf(array $pipeline)
{
    return DB::table('leads')->where('lead_pipeline_id', $pipeline['pipeline'])->get();
}

it('opens a card in the other pipeline when a deal is won, with the contact and no value', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    ruleFor($sales, $post);

    $owner = ruleUser();
    $deal = ruleDeal($sales, $owner);
    $tagId = DB::table('tags')->insertGetId(['name' => 'zz-tag-'.uniqid(), 'color' => '#000000', 'user_id' => $owner, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('lead_tags')->insert(['lead_id' => $deal->id, 'tag_id' => $tagId]);

    winDeal($deal, $sales);

    $cards = cardsOf($post);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]->title)->toBe('ZZ venda')
        ->and((int) $cards[0]->lead_pipeline_stage_id)->toBe($post['open'])
        ->and((int) $cards[0]->user_id)->toBe($owner)
        ->and((float) $cards[0]->lead_value)->toBe(0.0)
        ->and(DB::table('lead_tags')->where('lead_id', $cards[0]->id)->pluck('tag_id')->all())->toBe([$tagId]);
});

it('opens only one card per rule even when the deal is won again', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    ruleFor($sales, $post);

    $deal = ruleDeal($sales);
    winDeal($deal, $sales);
    $deal->update(['lead_pipeline_stage_id' => $sales['open']]);
    winDeal($deal->refresh(), $sales);

    expect(cardsOf($post))->toHaveCount(1);
});

it('does nothing for an inactive rule, another pipeline or a deal that is not won', function () {
    $sales = rulePipeline('vendas');
    $other = rulePipeline('outro');
    $post = rulePipeline('pos');
    ruleFor($sales, $post, ['is_active' => false]);
    ruleFor($other, $post);

    winDeal(ruleDeal($sales), $sales);
    ruleDeal($other);

    expect(cardsOf($post))->toHaveCount(0);

    $lost = ruleDeal($other);
    $lost->update(['lead_pipeline_stage_id' => $other['lost']]);

    expect(cardsOf($post))->toHaveCount(0);
});

it('copies the value when the rule asks for it', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    ruleFor($sales, $post, ['copy_value' => true]);

    winDeal(ruleDeal($sales), $sales);

    expect((float) cardsOf($post)[0]->lead_value)->toBe(1500.0);
});

it('gives the card to a fixed person, or in turns to the people of the round robin', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    $a = ruleUser();
    $b = ruleUser();

    $fixed = ruleFor($sales, $post, ['assignment_mode' => 'fixed', 'assigned_user_id' => $a]);
    winDeal(ruleDeal($sales, ruleUser()), $sales);

    expect((int) cardsOf($post)[0]->user_id)->toBe($a);

    $fixed->delete();
    DB::table('leads')->where('lead_pipeline_id', $post['pipeline'])->delete();

    ruleFor($sales, $post, ['assignment_mode' => 'round_robin', 'pool_user_ids' => [$a, $b]]);

    foreach (range(1, 3) as $i) {
        winDeal(ruleDeal($sales), $sales);
    }

    expect(cardsOf($post)->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe(collect([$a, $b, $a])->sort()->values()->all());
});

it('moves the WhatsApp conversation to the new card and its owner', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    ruleFor($sales, $post, ['assignment_mode' => 'fixed', 'assigned_user_id' => $owner = ruleUser()]);

    $deal = ruleDeal($sales);
    $session = DB::table('whatsapp_sessions')->value('id') ?? DB::table('whatsapp_sessions')->insertGetId(['name' => 'ZZ', 'channel' => 'waha', 'external_id' => 'zz-'.uniqid(), 'status' => 'WORKING', 'created_at' => now(), 'updated_at' => now()]);
    $conversation = DB::table('whatsapp_conversations')->insertGetId(['lead_id' => $deal->id, 'session_id' => $session, 'contact_phone' => '5511988887777', 'contact_name' => 'ZZ', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

    winDeal($deal, $sales);

    $row = DB::table('whatsapp_conversations')->find($conversation);

    expect((int) $row->lead_id)->toBe((int) cardsOf($post)[0]->id)
        ->and((int) $row->assigned_user_id)->toBe($owner);
});

it('keeps the conversation where it was when the rule does not move it', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    ruleFor($sales, $post, ['move_conversation' => false]);

    $deal = ruleDeal($sales);
    $session = DB::table('whatsapp_sessions')->value('id') ?? DB::table('whatsapp_sessions')->insertGetId(['name' => 'ZZ', 'channel' => 'waha', 'external_id' => 'zz-'.uniqid(), 'status' => 'WORKING', 'created_at' => now(), 'updated_at' => now()]);
    $conversation = DB::table('whatsapp_conversations')->insertGetId(['lead_id' => $deal->id, 'session_id' => $session, 'contact_phone' => '5511977776666', 'contact_name' => 'ZZ', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

    winDeal($deal, $sales);

    expect((int) DB::table('whatsapp_conversations')->find($conversation)->lead_id)->toBe($deal->id);
});

it('validates the rule on the settings screen', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    $admin = getDefaultAdmin();

    $payload = fn (array $extra = []) => $extra + [
        'name' => 'Pós-venda', 'is_active' => true, 'source_pipeline_id' => $sales['pipeline'], 'target_pipeline_id' => $post['pipeline'],
        'target_stage_id' => $post['open'], 'assignment_mode' => 'owner', 'copy_value' => false, 'move_conversation' => true,
    ];

    test()->actingAs($admin, 'user')->get(route('admin.funnel-rules.index'))->assertOk();

    test()->postJson(route('admin.funnel-rules.store'), $payload())->assertOk();
    expect(FunnelRule::query()->where('source_pipeline_id', $sales['pipeline'])->count())->toBe(1);

    // Same pipeline, a closing stage, and a missing person are refused.
    test()->postJson(route('admin.funnel-rules.store'), $payload(['target_pipeline_id' => $sales['pipeline'], 'target_stage_id' => $sales['open']]))->assertUnprocessable();
    test()->postJson(route('admin.funnel-rules.store'), $payload(['target_stage_id' => $post['won']]))->assertUnprocessable();
    test()->postJson(route('admin.funnel-rules.store'), $payload(['assignment_mode' => 'fixed']))->assertUnprocessable();
    test()->postJson(route('admin.funnel-rules.store'), $payload(['assignment_mode' => 'round_robin', 'pool_user_ids' => []]))->assertUnprocessable();

    $id = FunnelRule::query()->where('source_pipeline_id', $sales['pipeline'])->value('id');

    test()->putJson(route('admin.funnel-rules.update', $id), $payload(['is_active' => false]))->assertOk();
    expect(FunnelRule::query()->find($id)->is_active)->toBeFalse();

    test()->deleteJson(route('admin.funnel-rules.delete', $id))->assertOk();
    expect(FunnelRule::query()->find($id))->toBeNull();
});

it('copies the custom fields but not the deal own ones', function () {
    $sales = rulePipeline('vendas');
    $post = rulePipeline('pos');
    ruleFor($sales, $post);

    $attributeId = DB::table('attributes')->insertGetId(['code' => 'zz_utm_'.uniqid(), 'name' => 'ZZ UTM', 'type' => 'text', 'entity_type' => 'leads', 'created_at' => now(), 'updated_at' => now()]);

    $deal = ruleDeal($sales);
    DB::table('attribute_values')->insert(['entity_type' => 'leads', 'entity_id' => $deal->id, 'attribute_id' => $attributeId, 'text_value' => 'campanha-x']);

    winDeal($deal, $sales);

    $card = cardsOf($post)[0];

    expect(DB::table('attribute_values')->where('entity_id', $card->id)->where('attribute_id', $attributeId)->value('text_value'))->toBe('campanha-x')
        ->and(DB::table('attribute_values')->where('entity_id', $deal->id)->where('attribute_id', $attributeId)->count())->toBe(1);
});
