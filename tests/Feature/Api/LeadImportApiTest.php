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
