<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Api\Models\ApiKey;

uses(DatabaseTransactions::class);

beforeEach(fn () => test()->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class));

/**
 * REST API v1: tags on leads (POST /leads "tags" and POST /leads/{id}/tags).
 * Needs a migrated and seeded database (default pipeline + admin user).
 */
function apiHeaders(array $abilities = ['read', 'write']): array
{
    [, $token] = ApiKey::issue('test', $abilities, null);

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function makeTag(string $name): int
{
    return DB::table('tags')->insertGetId([
        'name'       => $name,
        'color'      => '#000000',
        'user_id'    => DB::table('users')->min('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function leadTagIds(int $leadId): array
{
    return DB::table('lead_tags')->where('lead_id', $leadId)->orderBy('tag_id')->pluck('tag_id')->map(fn ($id) => (int) $id)->all();
}

it('creates a lead with tags', function () {
    $hot = makeTag('Quente teste');

    $response = test()->postJson('/api/v1/leads', [
        'title'  => 'Lead com tag',
        'person' => ['name' => 'Fulano Tag', 'phone' => '+55 11 90000-0001'],
        'tags'   => [$hot],
    ], apiHeaders());

    $response->assertStatus(201)
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.tags', ['Quente teste']);

    expect(leadTagIds($response->json('data.id')))->toBe([$hot]);
});

it('rejects unknown tag ids without creating the lead', function () {
    $before = DB::table('leads')->count();

    test()->postJson('/api/v1/leads', [
        'title'  => 'Lead tag invalida',
        'person' => ['name' => 'Fulano Invalido'],
        'tags'   => [999999],
    ], apiHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_tags')
        ->assertJsonPath('tags', [999999]);

    expect(DB::table('leads')->count())->toBe($before);
});

it('adds tags to the open lead returned by on_duplicate', function () {
    $first = makeTag('Primeira');
    $second = makeTag('Segunda');
    $person = ['name' => 'Fulano Duplicado', 'phone' => '+55 11 90000-0002'];

    $created = test()->postJson('/api/v1/leads', ['title' => 'Original', 'person' => $person, 'tags' => [$first]], apiHeaders())
        ->assertStatus(201);

    test()->postJson('/api/v1/leads', [
        'title'        => 'Reenvio',
        'person'       => $person,
        'on_duplicate' => 'return',
        'tags'         => [$second, $first],
    ], apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.id', $created->json('data.id'));

    expect(leadTagIds($created->json('data.id')))->toBe([$first, $second]);
});

it('adds tags to a lead idempotently, keeping the existing ones', function () {
    $first = makeTag('Existente');
    $second = makeTag('Nova');

    $leadId = test()->postJson('/api/v1/leads', [
        'title'  => 'Lead para tags',
        'person' => ['name' => 'Fulano Endpoint'],
        'tags'   => [$first],
    ], apiHeaders())->json('data.id');

    $headers = apiHeaders();

    foreach ([1, 2] as $attempt) {
        test()->postJson("/api/v1/leads/{$leadId}/tags", ['tags' => [$second, $second]], $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $leadId)
            ->assertJsonStructure(['data' => ['tags', 'activities']]);
    }

    expect(leadTagIds($leadId))->toBe([$first, $second]);
});

it('returns 404 for an unknown lead and 422 for an unknown tag', function () {
    $tag = makeTag('Qualquer');

    test()->postJson('/api/v1/leads/999999/tags', ['tags' => [$tag]], apiHeaders())
        ->assertStatus(404)
        ->assertJsonPath('error', 'not_found');

    $leadId = test()->postJson('/api/v1/leads', ['title' => 'Lead 422', 'person' => ['name' => 'Fulano 422']], apiHeaders())
        ->json('data.id');

    test()->postJson("/api/v1/leads/{$leadId}/tags", ['tags' => [$tag, 999999]], apiHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_tags');

    expect(leadTagIds($leadId))->toBe([]);
});

it('refuses tag changes with a read-only key', function () {
    $tag = makeTag('Somente leitura');

    $leadId = test()->postJson('/api/v1/leads', ['title' => 'Lead 403', 'person' => ['name' => 'Fulano 403']], apiHeaders())
        ->json('data.id');

    test()->postJson("/api/v1/leads/{$leadId}/tags", ['tags' => [$tag]], apiHeaders(['read']))
        ->assertStatus(403)
        ->assertJsonPath('error', 'forbidden');

    expect(leadTagIds($leadId))->toBe([]);
});
