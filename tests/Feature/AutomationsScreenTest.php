<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Providers\AutomationsServiceProvider;
use Webkul\Lead\Models\Pipeline;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Configurações → Automação → Automações: the screen, validation, permissions, simulation, history.
 */
beforeEach(function () {
    config(['automations.enabled' => true]);
    app()->setLocale('pt_BR');
});

function ausPipeline(): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ tela '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $stage = fn (string $code, int $order) => DB::table('lead_pipeline_stages')->insertGetId(['name' => 'E '.$code, 'code' => $code, 'probability' => 10, 'sort_order' => $order, 'lead_pipeline_id' => $pipelineId]);

    Pipeline::forgetCounted();

    return ['pipeline' => $pipelineId, 'open' => $stage('zz-new-'.uniqid(), 1), 'won' => $stage('won', 98), 'lost' => $stage('lost', 99)];
}

function ausPayload(array $pipeline, array $override = []): array
{
    return array_replace([
        'name' => 'ZZ regra da tela',
        'status' => 'test',
        'trigger_type' => 'stage_entered',
        'trigger_config' => ['pipeline_id' => $pipeline['pipeline'], 'stage_id' => $pipeline['won'], 'from_stage_id' => null],
        'conditions' => [['type' => 'pipeline', 'pipeline_id' => $pipeline['pipeline']]],
        'actions' => [['type' => 'create_task', 'title' => 'Ligar para {{nome}}', 'due_in_days' => 1, 'assignee' => 'owner']],
        'run_once_per_lead' => true,
    ], $override);
}

function ausAdmin(): User
{
    return User::query()->findOrFail(DB::table('users')->where('role_id', DB::table('roles')->where('permission_type', 'all')->value('id'))->min('id'));
}

it('shows the screen with the recipes', function () {
    $this->actingAs(ausAdmin(), 'user')
        ->get(route('admin.automations.index'))
        ->assertOk()
        ->assertSee('v-automations', false)
        ->assertSee('meeting_to_closer', false);
});

it('does not exist while the module is off', function () {
    config(['automations.enabled' => false]);

    $this->actingAs(ausAdmin(), 'user')->get(route('admin.automations.index'))->assertNotFound();
    $this->actingAs(ausAdmin(), 'user')->postJson(route('admin.automations.store'), [])->assertNotFound();
});

it('refuses a user without the automations permission', function () {
    $roleId = DB::table('roles')->insertGetId(['name' => 'ZZ sem automações', 'description' => 'x', 'permission_type' => 'custom', 'permissions' => json_encode(['dashboard']), 'created_at' => now(), 'updated_at' => now()]);
    $userId = DB::table('users')->insertGetId(['name' => 'ZZ', 'email' => 'zz-'.uniqid().'@example.test', 'password' => 'x', 'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs(User::query()->findOrFail($userId), 'user')->get(route('admin.automations.index'))->assertStatus(401);
});

it('creates a valid rule', function () {
    $p = ausPipeline();

    $response = $this->actingAs(ausAdmin(), 'user')->postJson(route('admin.automations.store'), ausPayload($p))->assertOk();

    $rule = AutomationRule::query()->findOrFail($response->json('data.id'));

    expect($rule->status)->toBe('test')
        ->and($rule->trigger_config['stage_id'])->toBe($p['won'])
        ->and($rule->actions[0]['title'])->toBe('Ligar para {{nome}}')
        ->and($rule->created_by)->toBe(ausAdmin()->id);
});

it('refuses a stage from another pipeline, a lost stage to move to and unknown ids', function () {
    $p = ausPipeline();
    $other = ausPipeline();
    $admin = ausAdmin();

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, [
        'trigger_config' => ['pipeline_id' => $p['pipeline'], 'stage_id' => $other['won']],
    ]))->assertStatus(422)->assertJsonValidationErrors('trigger_config.stage_id');

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, [
        'actions' => [['type' => 'move_stage', 'stage_id' => $p['lost']]],
    ]))->assertStatus(422)->assertJsonValidationErrors('actions.0.stage_id');

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, [
        'actions' => [['type' => 'transfer_pipeline', 'pipeline_id' => $other['pipeline'], 'stage_id' => $p['open']]],
    ]))->assertStatus(422)->assertJsonValidationErrors('actions.0.stage_id');

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, [
        'actions' => [['type' => 'add_tag', 'tag_id' => 99999999], ['type' => 'assign_owner', 'user_id' => 99999999]],
    ]))->assertStatus(422)->assertJsonValidationErrors(['actions.0.tag_id', 'actions.1.user_id']);

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, ['actions' => []]))
        ->assertStatus(422)->assertJsonValidationErrors('actions');

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, ['trigger_type' => 'every_hour']))
        ->assertStatus(422)->assertJsonValidationErrors('trigger_type');
});

it('refuses webhook URLs that are not public http(s) and WhatsApp destinations without a number', function () {
    $p = ausPipeline();
    $admin = ausAdmin();

    foreach (['ftp://example.com/x', 'http://localhost/hook', 'http://127.0.0.1/x', 'http://10.0.0.5/x', 'http://192.168.1.10/x', 'https://user:pass@example.com', 'not a url'] as $url) {
        $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, [
            'actions' => [['type' => 'webhook', 'url' => $url, 'secret' => '']],
        ]))->assertStatus(422)->assertJsonValidationErrors('actions.0.url');
    }

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, [
        'actions' => [['type' => 'notify_whatsapp', 'to' => '123', 'message' => '']],
    ]))->assertStatus(422)->assertJsonValidationErrors(['actions.0.to', 'actions.0.message']);
});

it('never sends the webhook secret back and keeps it when unchanged', function () {
    $p = ausPipeline();
    $admin = ausAdmin();
    $payload = ausPayload($p, ['actions' => [['type' => 'webhook', 'url' => 'https://hooks.example.com/a', 'secret' => 'segredo-1']]]);

    $created = $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), $payload)->assertOk();

    expect($created->json('data.actions.0.secret'))->toBe('__keep__');

    $id = $created->json('data.id');
    $payload['actions'][0]['secret'] = '__keep__';
    $payload['name'] = 'renomeada';

    $this->actingAs($admin, 'user')->putJson(route('admin.automations.update', ['id' => $id]), $payload)->assertOk();

    expect(AutomationRule::query()->findOrFail($id)->actions[0]['secret'])->toBe('segredo-1');
});

it('stops at the rule limit of the CRM', function () {
    config(['automations.max_rules' => AutomationRule::query()->count() + 1]);
    $p = ausPipeline();
    $admin = ausAdmin();

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p))->assertOk();
    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p))->assertStatus(422);
});

it('switches the status and deletes a rule', function () {
    $p = ausPipeline();
    $admin = ausAdmin();
    $id = $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p))->json('data.id');

    $this->actingAs($admin, 'user')->patchJson(route('admin.automations.status', ['id' => $id]), ['status' => 'on'])->assertOk()->assertJsonPath('data.status', 'on');
    $this->actingAs($admin, 'user')->patchJson(route('admin.automations.status', ['id' => $id]), ['status' => 'maybe'])->assertStatus(422);
    $this->actingAs($admin, 'user')->deleteJson(route('admin.automations.delete', ['id' => $id]))->assertOk();

    expect(AutomationRule::query()->find($id))->toBeNull();
});

it('simulates on a lead without changing it and lists it in the history', function () {
    $p = ausPipeline();
    $admin = ausAdmin();
    $id = $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), ausPayload($p, [
        'actions' => [['type' => 'move_stage', 'stage_id' => $p['won']]],
    ]))->json('data.id');

    $leadId = DB::table('leads')->insertGetId(['title' => 'ZZ simulado', 'status' => 1, 'lead_pipeline_id' => $p['pipeline'], 'lead_pipeline_stage_id' => $p['open'], 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.simulate', ['id' => $id]), ['lead_id' => $leadId])
        ->assertOk()
        ->assertJsonPath('data.status', 'dry_run')
        ->assertJsonPath('data.trigger', 'simulation');

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.simulate', ['id' => $id]), ['lead_id' => 999999999])->assertNotFound();

    expect((int) DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id'))->toBe($p['open']);

    $this->actingAs($admin, 'user')->getJson(route('admin.automations.runs', ['rule_id' => $id, 'status' => 'dry_run']))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.lead_id', $leadId)
        ->assertJsonPath('data.0.lead_title', 'ZZ simulado');

    $this->actingAs($admin, 'user')->getJson(route('admin.automations.runs', ['rule_id' => $id, 'status' => 'failed']))
        ->assertOk()->assertJsonPath('meta.total', 0);
});

it('hides only the native workflows menu entry when asked', function () {
    $before = collect(config('menu.admin'))->pluck('key');

    config(['automations.hide_native' => false]);
    (new AutomationsServiceProvider(app()))->hideNativeWorkflows();
    expect(collect(config('menu.admin'))->pluck('key')->all())->toBe($before->all());

    config(['automations.hide_native' => true]);
    (new AutomationsServiceProvider(app()))->hideNativeWorkflows();
    $after = collect(config('menu.admin'))->pluck('key');

    expect($after->contains('settings.automation.workflows'))->toBeFalse()
        ->and($after->count())->toBe($before->count() - ($before->contains('settings.automation.workflows') ? 1 : 0));
});
