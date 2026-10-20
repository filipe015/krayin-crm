<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\Lead\Models\Pipeline;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Access by pipeline: a user restricted to some pipelines only sees the deals, conversations, agenda and
 * reports of those; everybody else keeps seeing everything.
 */
function accessPipeline(string $name): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ '.$name.' '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $stageId = DB::table('lead_pipeline_stages')->insertGetId(['name' => 'Novo', 'code' => 'new', 'probability' => 10, 'sort_order' => 1, 'lead_pipeline_id' => $pipelineId]);

    Pipeline::forgetCounted();

    return ['pipeline' => $pipelineId, 'stage' => $stageId];
}

function accessLead(array $pipeline): int
{
    return DB::table('leads')->insertGetId(['title' => 'ZZ deal '.uniqid(), 'lead_value' => 100, 'status' => 1, 'lead_pipeline_id' => $pipeline['pipeline'], 'lead_pipeline_stage_id' => $pipeline['stage'], 'created_at' => now(), 'updated_at' => now()]);
}

function accessConversation(?int $leadId): int
{
    $session = DB::table('whatsapp_sessions')->value('id') ?? DB::table('whatsapp_sessions')->insertGetId(['name' => 'ZZ sessão', 'channel' => 'waha', 'external_id' => 'zz-'.uniqid(), 'status' => 'WORKING', 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('whatsapp_conversations')->insertGetId([
        'lead_id' => $leadId, 'session_id' => $session, 'contact_phone' => '55119'.random_int(10000000, 99999999), 'contact_name' => 'ZZ contato',
        'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function accessUser(array $pipelineIds = [], bool $restricted = true, bool $queue = false): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZ acesso '.uniqid(), 'description' => 'teste', 'permission_type' => 'custom',
        'permissions' => json_encode(['leads', 'leads.view', 'leads.create', 'leads.edit', 'whatsapp', 'whatsapp.send', 'whatsapp.manage']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('users')->insertGetId([
        'name' => 'ZZ Acesso', 'email' => 'zz-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global',
        'pipelines_restricted' => $restricted, 'inbox_queue' => $queue, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($pipelineIds as $pipelineId) {
        DB::table('user_pipelines')->insert(['user_id' => $id, 'lead_pipeline_id' => $pipelineId]);
    }

    return User::query()->findOrFail($id);
}

it('opens the deals of the allowed pipeline and hides the others', function () {
    $mine = accessPipeline('meu');
    $other = accessPipeline('outro');
    $myLead = accessLead($mine);
    $otherLead = accessLead($other);

    $user = accessUser([$mine['pipeline']]);

    test()->actingAs($user, 'user')->get(route('admin.leads.view', ['id' => $myLead]))->assertOk();
    test()->actingAs($user, 'user')->get(route('admin.leads.view', ['id' => $otherLead]))->assertNotFound();
});

it('keeps the pipeline selector to the allowed ones and never falls back to a forbidden one', function () {
    $mine = accessPipeline('meu');
    $other = accessPipeline('outro');

    $user = accessUser([$mine['pipeline']]);

    test()->actingAs($user, 'user');

    expect(Pipeline::query()->pluck('id')->all())->toBe([$mine['pipeline']]);

    test()->get(route('admin.leads.index', ['pipeline_id' => $other['pipeline']]))->assertOk();
    expect(Pipeline::query()->whereKey($other['pipeline'])->exists())->toBeFalse();
});

it('refuses a restricted user that has no pipeline at all', function () {
    accessPipeline('qualquer');

    test()->actingAs(accessUser([]), 'user')->get(route('admin.leads.index'))->assertForbidden();
});

it('lets an unrestricted user see every pipeline', function () {
    $mine = accessPipeline('meu');
    $other = accessPipeline('outro');
    $otherLead = accessLead($other);

    $user = accessUser([$mine['pipeline']], restricted: false);

    test()->actingAs($user, 'user')->get(route('admin.leads.view', ['id' => $otherLead]))->assertOk();
});

it('shows a restricted user only the conversations of their pipelines, and the queue only when allowed', function () {
    $mine = accessPipeline('meu');
    $other = accessPipeline('outro');

    $mineConversation = accessConversation(accessLead($mine));
    $otherConversation = accessConversation(accessLead($other));
    $queueConversation = accessConversation(null);

    $ids = fn (User $user) => collect(test()->actingAs($user, 'user')->getJson(route('admin.whatsapp.inbox.conversations', ['tab' => 'all']))->assertOk()->json('data'))->pluck('id');

    $withoutQueue = $ids(accessUser([$mine['pipeline']], queue: false));

    expect($withoutQueue)->toContain($mineConversation)
        ->not->toContain($otherConversation)
        ->not->toContain($queueConversation);

    $withQueue = $ids(accessUser([$mine['pipeline']], queue: true));

    expect($withQueue)->toContain($mineConversation, $queueConversation)->not->toContain($otherConversation);

    test()->actingAs(accessUser([$mine['pipeline']]), 'user')->getJson(route('admin.whatsapp.inbox.messages', ['id' => $otherConversation]))->assertNotFound();
});

it('keeps a pipeline that is not allowed out of the reports', function () {
    $mine = accessPipeline('meu');
    $other = accessPipeline('outro');

    test()->actingAs(accessUser([$mine['pipeline']]), 'user');

    expect(Pipeline::reportableIds())->toBe([$mine['pipeline']]);
});

it('saves the access by pipeline on the user form', function () {
    $mine = accessPipeline('meu');
    $other = accessPipeline('outro');
    $admin = getDefaultAdmin();
    $target = accessUser([], restricted: false);

    $payload = fn (array $extra) => $extra + [
        'name' => 'ZZ Acesso', 'email' => $target->email, 'role_id' => $target->role_id, 'view_permission' => 'global', 'status' => 1,
    ];

    // Restricted but with nothing marked: refused.
    test()->actingAs($admin, 'user')
        ->putJson(route('admin.settings.users.update', $target->id), $payload(['pipelines_restricted' => 1]))
        ->assertUnprocessable();

    test()->putJson(route('admin.settings.users.update', $target->id), $payload(['pipelines_restricted' => 1, 'pipelines' => [$mine['pipeline']], 'inbox_queue' => 0]))
        ->assertOk();

    $target->refresh()->load('pipelines');

    expect($target->pipelines_restricted)->toBeTrue()
        ->and($target->inbox_queue)->toBeFalse()
        ->and($target->pipelines->pluck('id')->all())->toBe([$mine['pipeline']]);

    // Back to every pipeline: the list is emptied and the queue is open again.
    test()->putJson(route('admin.settings.users.update', $target->id), $payload(['pipelines_restricted' => 0, 'pipelines' => [$other['pipeline']]]))
        ->assertOk();

    $target->refresh()->load('pipelines');

    expect($target->pipelines_restricted)->toBeFalse()
        ->and($target->inbox_queue)->toBeTrue()
        ->and($target->pipelines)->toHaveCount(0);

    // An id that is not a pipeline is refused.
    test()->putJson(route('admin.settings.users.update', $target->id), $payload(['pipelines_restricted' => 1, 'pipelines' => [999999]]))
        ->assertUnprocessable();
});
