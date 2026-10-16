<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Webkul\WhatsApp\Api\Models\ApiKey;
use Webkul\WhatsApp\Api\Models\ApiWebhook;
use Webkul\WhatsApp\Api\Support\WebhookEvents;

/**
 * Configurações → Integração (API): keys for the REST API and outgoing webhooks.
 */
class ApiSettingController extends Controller
{
    public function index(): View
    {
        return view('whatsapp::api.settings', [
            'keys'     => $this->keys(),
            'webhooks' => $this->webhooks(),
            'events'   => WebhookEvents::EVENTS,
            'baseUrl'  => rtrim(config('app.url'), '/').'/api/v1',
            'docs'     => $this->documentation(rtrim(config('app.url'), '/').'/api/v1'),
            'urls'     => [
                'keys'     => route('admin.api.settings.keys.store'),
                'revoke'   => route('admin.api.settings.keys.revoke', ['id' => '__ID__']),
                'deleteKey' => route('admin.api.settings.keys.delete', ['id' => '__ID__']),
                'deleteRevoked' => route('admin.api.settings.keys.delete-revoked'),
                'webhooks' => route('admin.api.settings.webhooks.store'),
                'webhook'  => route('admin.api.settings.webhooks.update', ['id' => '__ID__']),
                'test'     => route('admin.api.settings.webhooks.test', ['id' => '__ID__']),
                'list'     => route('admin.api.settings.list'),
            ],
        ]);
    }

    /**
     * API.md (the same file kept in the repository) rendered for the screen, with this installation's address.
     */
    private function documentation(string $baseUrl): string
    {
        $path = __DIR__.'/../../../API.md';

        if (! File::exists($path)) {
            return '';
        }

        $markdown = str_replace('https://<seu-crm>/api/v1', $baseUrl, File::get($path));

        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function list(): JsonResponse
    {
        return response()->json(['keys' => $this->keys(), 'webhooks' => $this->webhooks()]);
    }

    public function storeKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(ApiKey::ABILITIES)],
        ]);

        // Each level includes the ones below it: delete > write > read.
        $abilities = match (true) {
            in_array('delete', $data['abilities'], true) => ['read', 'write', 'delete'],
            in_array('write', $data['abilities'], true)  => ['read', 'write'],
            default                                      => ['read'],
        };

        [$key, $token] = ApiKey::issue($data['name'], $abilities, auth()->guard('user')->id());

        // The only time the token is ever shown.
        return response()->json(['token' => $token, 'key' => $this->presentKey($key)], 201);
    }

    public function revokeKey(int $id): JsonResponse
    {
        ApiKey::query()->whereKey($id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return response()->json(['revoked' => true]);
    }

    /**
     * Removes revoked keys from the list (a key still in use must be revoked first).
     */
    public function deleteKey(int $id): JsonResponse
    {
        $key = ApiKey::query()->find($id);

        abort_if(! $key, 404);
        abort_if(! $key->revoked_at, 422, trans('whatsapp::app.api_settings.revoke_first'));

        $key->delete();

        return response()->json(['deleted' => true]);
    }

    public function deleteRevokedKeys(): JsonResponse
    {
        $deleted = ApiKey::query()->whereNotNull('revoked_at')->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $data = $this->validateWebhook($request);

        $secret = 'whsec_'.Str::random(40);

        $webhook = ApiWebhook::create($data + ['secret' => $secret, 'is_active' => true]);

        return response()->json(['secret' => $secret, 'webhook' => $this->presentWebhook($webhook)], 201);
    }

    public function updateWebhook(Request $request, int $id): JsonResponse
    {
        $webhook = ApiWebhook::query()->findOrFail($id);

        $data = $this->validateWebhook($request);

        $webhook->update($data + ['is_active' => $request->boolean('is_active', true), 'failures' => 0]);

        $response = ['webhook' => $this->presentWebhook($webhook->refresh())];

        if ($request->boolean('rotate_secret')) {
            $secret = 'whsec_'.Str::random(40);

            $webhook->update(['secret' => $secret]);

            $response['secret'] = $secret;
        }

        return response()->json($response);
    }

    public function destroyWebhook(int $id): JsonResponse
    {
        ApiWebhook::query()->whereKey($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function testWebhook(int $id): JsonResponse
    {
        $webhook = ApiWebhook::query()->findOrFail($id);

        $result = WebhookEvents::deliver($webhook, 'ping', ['message' => 'Teste de webhook do CRM.']);

        return response()->json($result + ['webhook' => $this->presentWebhook($webhook->refresh())]);
    }

    private function validateWebhook(Request $request): array
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:80'],
            'url'      => ['required', 'url', 'max:500', 'regex:#^https?://#i'],
            'events'   => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_merge(WebhookEvents::EVENTS, ['*']))],
        ]);

        $data['events'] = in_array('*', $data['events'], true) ? ['*'] : array_values(array_unique($data['events']));

        return $data;
    }

    private function keys(): array
    {
        return ApiKey::query()->orderByDesc('id')->get()->map(fn ($key) => $this->presentKey($key))->all();
    }

    private function webhooks(): array
    {
        return ApiWebhook::query()->orderByDesc('id')->get()->map(fn ($webhook) => $this->presentWebhook($webhook))->all();
    }

    private function presentKey(ApiKey $key): array
    {
        return [
            'id'           => $key->id,
            'name'         => $key->name,
            'prefix'       => $key->key_prefix,
            'abilities'    => $key->abilities,
            'last_used_at' => $key->last_used_at?->format('d/m/Y H:i'),
            'last_used_ip' => $key->last_used_ip,
            'created_at'   => $key->created_at?->format('d/m/Y H:i'),
            'revoked'      => (bool) $key->revoked_at,
        ];
    }

    private function presentWebhook(ApiWebhook $webhook): array
    {
        return [
            'id'           => $webhook->id,
            'name'         => $webhook->name,
            'url'          => $webhook->url,
            'events'       => $webhook->events,
            'is_active'    => $webhook->is_active,
            'last_status'  => $webhook->last_status,
            'last_error'   => $webhook->last_error,
            'last_sent_at' => $webhook->last_sent_at?->format('d/m/Y H:i'),
            'failures'     => $webhook->failures,
        ];
    }
}
