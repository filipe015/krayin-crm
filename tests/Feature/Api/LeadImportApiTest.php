<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\WhatsApp\Api\Models\ApiKey;
use Webkul\WhatsApp\Api\Models\ApiWebhook;
use Webkul\WhatsApp\Api\Support\WebhookEvents;

uses(DatabaseTransactions::class);

/**
 * REST API v1 for imports: funnel creation, external ids (no duplicates), original dates, silence.
 * Needs a migrated and seeded database.
 */
function importHeaders(): array
{
    [, $token] = ApiKey::issue('import-test', ['read', 'write'], null);

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

beforeEach(function () {
    WebhookEvents::reset();

    // The suite makes more than the 120 calls/minute the API allows a client.
    test()->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
});

it('creates a funnel with its stages once, adding won and lost', function () {
    $payload = [
        'name'   => 'Import Teste '.uniqid(),
        'stages' => [['name' => 'Lead gerado'], ['name' => 'Em contato'], ['name' => 'Reunião']],
    ];

    $first = test()->postJson('/api/v1/pipelines', $payload, importHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.created', true);

    $codes = collect($first->json('data.stages'))->pluck('code')->all();

    expect($first->json('data.stages'))->toHaveCount(5)
        ->and($codes)->toContain('won', 'lost')
        ->and(collect($first->json('data.stages'))->pluck('name')->take(3)->all())->toBe(['Lead gerado', 'Em contato', 'Reunião']);

    test()->postJson('/api/v1/pipelines', $payload, importHeaders())
        ->assertStatus(200)
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.id', $first->json('data.id'));
});

it('keeps original dates and does not duplicate an external id', function () {
    $pipeline = test()->postJson('/api/v1/pipelines', [
        'name'   => 'Import Datas '.uniqid(),
        'stages' => [['name' => 'Novo']],
    ], importHeaders())->json('data');

    $won = collect($pipeline['stages'])->firstWhere('code', 'won');

    $body = [
        'title'      => 'Cartão antigo',
        'person'     => ['name' => 'Pessoa Importada', 'email' => 'importada-'.uniqid().'@example.com'],
        'stage_id'   => $won['id'],
        'created_at' => '2026-03-10T09:30:00-03:00',
        'closed_at'  => '2026-04-02T17:00:00-03:00',
        'external'   => ['source' => 'trello-test', 'id' => 'card-'.uniqid()],
        'silent'     => true,
    ];

    $lost = collect($pipeline['stages'])->firstWhere('code', 'lost');

    $lostLead = test()->postJson('/api/v1/leads', [
        'title'       => 'Perdido importado',
        'person'      => ['name' => 'Pessoa Perdida'],
        'stage_id'    => $lost['id'],
        'lost_reason' => 'Segmento restaurantes',
    ], importHeaders())->assertStatus(201)->json('data');

    expect(DB::table('leads')->where('id', $lostLead['id'])->value('lost_reason'))->toBe('Segmento restaurantes');

    $first = test()->postJson('/api/v1/leads', $body, importHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.status', 'won');

    $row = DB::table('leads')->where('id', $first->json('data.id'))->first();

    expect(substr($row->created_at, 0, 16))->toBe('2026-03-10 09:30')
        ->and(substr($row->closed_at, 0, 16))->toBe('2026-04-02 17:00')
        ->and(substr($row->stage_entered_at, 0, 16))->toBe('2026-03-10 09:30');

    $again = test()->postJson('/api/v1/leads', $body, importHeaders())
        ->assertStatus(200)
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.external_existed', true)
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(DB::table('api_external_refs')->where('source', 'trello-test')->count())->toBe(1);
});

it('dates an imported note like the original comment', function () {
    $lead = test()->postJson('/api/v1/leads', [
        'title'  => 'Lead com nota',
        'person' => ['name' => 'Pessoa Nota'],
    ], importHeaders())->json('data');

    $note = test()->postJson("/api/v1/leads/{$lead['id']}/notes", [
        'comment'    => 'Comentário do cartão',
        'created_at' => '2026-02-01T10:00:00-03:00',
        'silent'     => true,
    ], importHeaders())->assertStatus(201);

    $created = DB::table('activities')->where('id', $note->json('data.id'))->value('created_at');

    expect(substr($created, 0, 16))->toBe('2026-02-01 10:00');
});

it('sends no webhook for a silent import but does for a normal create', function () {
    Http::fake();

    ApiWebhook::create([
        'name' => 'teste', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'whsec_test',
        'events' => ['lead.created'], 'is_active' => true,
    ]);

    test()->postJson('/api/v1/leads', [
        'title' => 'Silencioso', 'person' => ['name' => 'Pessoa Silencio'], 'silent' => true,
    ], importHeaders())->assertStatus(201);

    WebhookEvents::flush();

    Http::assertNothingSent();

    WebhookEvents::reset();

    test()->postJson('/api/v1/leads', [
        'title' => 'Barulhento', 'person' => ['name' => 'Pessoa Barulho'],
    ], importHeaders())->assertStatus(201);

    WebhookEvents::flush();

    Http::assertSentCount(1);
});

it('creates a tag once, whatever the case of the name', function () {
    $name = 'Etiqueta Import '.uniqid();

    $first = test()->postJson('/api/v1/tags', ['name' => $name, 'color' => '#112233'], importHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.created', true);

    test()->postJson('/api/v1/tags', ['name' => mb_strtoupper($name)], importHeaders())
        ->assertStatus(200)
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.id', $first->json('data.id'));
});

it('creates several leads without e-mail or phone', function () {
    $ids = collect(['Sem Contato A', 'Sem Contato B', 'Sem Contato C'])->map(
        fn ($name) => test()->postJson('/api/v1/leads', ['title' => $name, 'person' => ['name' => $name]], importHeaders())
            ->assertStatus(201)
            ->json('data.person.id')
    );

    expect($ids->unique()->count())->toBe(3);
});

it('creates a source once and puts the company on the person', function () {
    $name = 'Origem Import '.uniqid();

    $first = test()->postJson('/api/v1/sources', ['name' => $name], importHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.created', true);

    test()->postJson('/api/v1/sources', ['name' => mb_strtoupper($name)], importHeaders())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $first->json('data.id'));

    $company = 'Empresa Import '.uniqid();

    foreach (['com contato' => ['email' => 'org-'.uniqid().'@example.com'], 'sem contato' => []] as $label => $contact) {
        $lead = test()->postJson('/api/v1/leads', [
            'title'     => 'Lead '.$label,
            'source_id' => $first->json('data.id'),
            'person'    => ['name' => 'Pessoa '.$label, 'organization' => $company] + $contact,
        ], importHeaders())->assertStatus(201)->assertJsonPath('data.source.id', $first->json('data.id'))->json('data');

        $organization = DB::table('persons')
            ->join('organizations', 'organizations.id', '=', 'persons.organization_id')
            ->where('persons.id', $lead['person']['id'])
            ->value('organizations.name');

        expect($organization)->toBe($company);
    }
});

it('imports again after the earlier lead was deleted', function () {
    $body = [
        'title'    => 'Apagado e reimportado',
        'person'   => ['name' => 'Pessoa Reimport'],
        'external' => ['source' => 'trello-test', 'id' => 'card-'.uniqid()],
    ];

    $first = test()->postJson('/api/v1/leads', $body, importHeaders())->assertStatus(201)->json('data.id');

    DB::table('leads')->where('id', $first)->delete();

    $second = test()->postJson('/api/v1/leads', $body, importHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.created', true)
        ->json('data.id');

    expect($second)->not->toBe($first);

    test()->postJson('/api/v1/leads', $body, importHeaders())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $second);
});

it('creates a custom field once and stores its value on a lead', function () {
    $name = 'Campo Import '.uniqid();

    $field = test()->postJson('/api/v1/attributes', ['entity' => 'leads', 'name' => $name, 'type' => 'text'], importHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.created', true)
        ->json('data');

    test()->postJson('/api/v1/attributes', ['entity' => 'leads', 'name' => mb_strtoupper($name), 'type' => 'text'], importHeaders())
        ->assertStatus(200)
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.code', $field['code']);

    test()->getJson('/api/v1/attributes?entity=leads', importHeaders())
        ->assertStatus(200)
        ->assertJsonFragment(['code' => $field['code']]);

    $lead = test()->postJson('/api/v1/leads', [
        'title'  => 'Lead com campo',
        'person' => ['name' => 'Pessoa Campo'],
        'custom' => [$field['code'] => 'valor importado'],
    ], importHeaders())->assertStatus(201)->json('data');

    expect($lead['custom'][$field['code']])->toBe('valor importado');
});

function deleteHeaders(array $abilities = ['read', 'write', 'delete']): array
{
    [, $token] = ApiKey::issue('delete-test', $abilities, null);

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

it('refuses deletions without the delete ability', function () {
    $lead = test()->postJson('/api/v1/leads', ['title' => 'Nao apagar', 'person' => ['name' => 'Pessoa Protegida']], importHeaders())
        ->assertStatus(201)->json('data');

    test()->deleteJson('/api/v1/leads/'.$lead['id'], [], importHeaders())
        ->assertStatus(403)
        ->assertJsonPath('error', 'forbidden');

    expect(DB::table('leads')->where('id', $lead['id'])->exists())->toBeTrue();
});

it('purges an import: leads, people, then the unused funnel and field', function () {
    $source = 'trello-del-'.uniqid();
    $pipeline = test()->postJson('/api/v1/pipelines', ['name' => 'Funil Del '.uniqid(), 'stages' => [['name' => 'Novo']]], importHeaders())->json('data');
    $field = test()->postJson('/api/v1/attributes', ['entity' => 'leads', 'name' => 'Campo Del '.uniqid(), 'type' => 'text'], importHeaders())->json('data');

    foreach (['Um', 'Dois'] as $n) {
        test()->postJson('/api/v1/leads', [
            'title'    => 'Importado '.$n,
            'person'   => ['name' => 'Pessoa Del '.$n, 'email' => 'del-'.strtolower($n).'-'.uniqid().'@example.com'],
            'stage_id' => $pipeline['stages'][0]['id'],
            'custom'   => [$field['code'] => 'valor'],
            'external' => ['source' => $source, 'id' => 'c-'.$n.uniqid()],
        ], importHeaders())->assertStatus(201);
    }

    $headers = deleteHeaders();

    // in use: refused
    test()->deleteJson('/api/v1/pipelines/'.$pipeline['id'], [], $headers)->assertStatus(409)->assertJsonPath('error', 'in_use');
    test()->deleteJson('/api/v1/attributes/'.$field['code'].'?entity=leads', [], $headers)->assertStatus(409)->assertJsonPath('data', null);

    // dry run counts, deletes nothing
    test()->deleteJson("/api/v1/imports/{$source}", [], $headers)
        ->assertStatus(200)
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.leads', 2);

    test()->deleteJson("/api/v1/imports/{$source}?confirm=yes", [], $headers)
        ->assertStatus(200)
        ->assertJsonPath('data.leads_deleted', 2)
        ->assertJsonPath('data.people_deleted', 2);

    test()->getJson("/api/v1/imports/{$source}", $headers)->assertJsonPath('data.leads', 0);

    // now free
    test()->deleteJson('/api/v1/attributes/'.$field['code'].'?entity=leads', [], $headers)->assertStatus(200)->assertJsonPath('data.deleted', true);
    test()->deleteJson('/api/v1/pipelines/'.$pipeline['id'], [], $headers)->assertStatus(200)->assertJsonPath('data.deleted', true);

    expect(DB::table('lead_pipelines')->where('id', $pipeline['id'])->exists())->toBeFalse();
});

it('refuses to delete a system field or the default funnel', function () {
    test()->deleteJson('/api/v1/attributes/title?entity=leads', [], deleteHeaders())->assertStatus(422)->assertJsonPath('error', 'system_field');

    $default = DB::table('lead_pipelines')->where('is_default', 1)->value('id');

    if ($default) {
        test()->deleteJson('/api/v1/pipelines/'.$default, [], deleteHeaders())->assertStatus(422)->assertJsonPath('error', 'default_pipeline');
    }
});
