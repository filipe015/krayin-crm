<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\Client\Factory;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Channels\Cloud\CloudClient;
use Webkul\WhatsApp\Channels\Cloud\EmbeddedSignup;
use Webkul\WhatsApp\Channels\Waha\WahaClient;
use Webkul\WhatsApp\Channels\Waha\WahaException;
use Webkul\WhatsApp\Models\Session;

/**
 * Connections screen: shows the WAHA sessions (numbers) and lets an operator pair,
 * restart or disconnect one without opening the WAHA dashboard.
 */
class ConnectionController extends Controller
{
    private const ACTIONS = ['start', 'stop', 'restart', 'logout'];

    public function index(): View
    {
        return view('whatsapp::connections.index', [
            'urls' => [
                'list'   => route('admin.whatsapp.connections.list'),
                'action' => route('admin.whatsapp.connections.action', ['id' => '__ID__', 'action' => '__ACTION__']),
                'qr'     => route('admin.whatsapp.connections.qr', ['id' => '__ID__']),
                'code'   => route('admin.whatsapp.connections.code', ['id' => '__ID__']),
                'cloudConnect'    => route('admin.whatsapp.connections.cloud.connect'),
                'cloudEmbedded'   => route('admin.whatsapp.connections.cloud.embedded'),
                'templates'       => route('admin.whatsapp.templates.index'),
                'cloudRefresh'    => route('admin.whatsapp.connections.cloud.refresh', ['id' => '__ID__']),
                'cloudDisconnect' => route('admin.whatsapp.connections.cloud.disconnect', ['id' => '__ID__']),
                'rules'           => route('admin.whatsapp.button-rules.list'),
                'ruleStore'       => route('admin.whatsapp.button-rules.store'),
                'ruleDelete'      => route('admin.whatsapp.button-rules.delete', ['id' => '__ID__']),
                'reminders'         => route('admin.whatsapp.reminders.show'),
                'reminderTemplates' => route('admin.whatsapp.reminders.templates'),
            ],
            'cloudWebhook' => route('admin.whatsapp.cloud.webhook'),
            'cloudVerifyConfigured' => filled(config('whatsapp.cloud.verify_token')),
            'cloudSecretConfigured' => filled(config('whatsapp.cloud.app_secret')),
            // Public identifiers only: the app secret never reaches the browser.
            'embeddedSignup' => [
                'ready'       => EmbeddedSignup::configured(),
                'appId'       => (string) config('whatsapp.cloud.app_id'),
                'configId'    => (string) config('whatsapp.cloud.es_config_id'),
                'version'     => (string) config('whatsapp.cloud.graph_version', 'v23.0'),
                'coexistence' => (bool) config('whatsapp.cloud.es_coexistence', true),
            ],
        ]);
    }

    /**
     * WAHA is the source of truth for status; the local table is refreshed from it so
     * the Inbox keeps working with the same data when WAHA is briefly unreachable.
     */
    public function list(): JsonResponse
    {
        $error = null;

        try {
            foreach (WahaClient::fromConfig()->sessions() as $remote) {
                $name = (string) ($remote['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $session = Session::query()->firstOrCreate(
                    ['channel' => 'waha', 'external_id' => $name],
                    ['name' => $name],
                );

                $status = strtoupper((string) ($remote['status'] ?? $session->status));

                if ($status !== $session->status) {
                    $session->status = $status;
                    $session->status_changed_at = now();
                }

                $paired = $this->phone($remote['me']['id'] ?? null);

                // A session waiting for a scan has no account any more (after a logout, for instance).
                $session->phone = $paired ?? ($status === 'SCAN_QR_CODE' ? null : $session->phone);
                $session->save();
            }
        } catch (WahaException $exception) {
            $error = $exception->getMessage();
        }

        return response()->json([
            'error' => $error,
            'data'  => Session::query()->where('channel', 'waha')->orderBy('name')->get()->map(fn (Session $session) => [
                'id'         => $session->id,
                'name'       => $session->name,
                'status'     => $session->status,
                'phone'      => $session->phone,
                'changed_at' => $session->status_changed_at?->toIso8601String(),
            ])->values(),
            'cloud' => Session::query()->where('channel', 'cloud')->orderBy('name')->get()
                ->map(fn (Session $session) => $this->cloudData($session))
                ->values(),
        ]);
    }

    /**
     * Connects an official WhatsApp number. The token is validated against Meta before
     * anything is stored, and it is never sent back to the browser.
     */
    public function connectCloud(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'waba_id'         => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'access_token'    => ['required', 'string', 'min:20', 'max:2000'],
            'name'            => ['nullable', 'string', 'max:80'],
        ]);

        $client = new CloudClient(
            app(Factory::class),
            $data['access_token'],
            $data['phone_number_id'],
            $data['waba_id'],
            (string) config('whatsapp.cloud.graph_version', 'v23.0'),
        );

        try {
            $profile = $client->profile();
        } catch (CloudApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $session = $this->storeCloud($data['phone_number_id'], $data['waba_id'], $data['access_token'], $profile, $data['name'] ?? null);

        // Without this subscription Meta does not deliver the WABA's webhooks to the app.
        $subscription = $this->subscribe($client);

        $this->remember($session, ['webhook_override' => $subscription['override']]);

        return response()->json(['data' => $this->cloudData($session), 'subscribed' => $subscription['subscribed']], 201);
    }

    /**
     * Embedded Signup: the customer connected their own number in Meta's popup. The browser
     * sends the one-time code and the ids the popup reported; the token is obtained here,
     * stored encrypted and never sent back.
     */
    public function connectEmbedded(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'            => ['required', 'string', 'min:10', 'max:4000'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'waba_id'         => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'event'           => ['nullable', 'string', 'max:80'],
            'pin'             => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'name'            => ['nullable', 'string', 'max:80'],
        ]);

        abort_unless(EmbeddedSignup::configured(), 422, trans('whatsapp::app.connections.es_not_configured'));

        try {
            $token = EmbeddedSignup::fromConfig()->exchangeCode($data['code']);
        } catch (CloudApiException $exception) {
            return response()->json(['message' => trans('whatsapp::app.connections.es_code_failed', ['reason' => $exception->getMessage()])], 422);
        }

        $client = new CloudClient(
            app(Factory::class),
            $token,
            $data['phone_number_id'],
            $data['waba_id'],
            (string) config('whatsapp.cloud.graph_version', 'v23.0'),
        );

        try {
            $profile = $client->profile();
        } catch (CloudApiException $exception) {
            return response()->json(['message' => trans('whatsapp::app.connections.es_number_failed', ['reason' => $exception->getMessage()])], 422);
        }

        // A number that stays on the WhatsApp Business app (coexistence) is already active;
        // registering it would be refused. Any other number must be registered once.
        $coexistence = ($data['event'] ?? null) === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';

        $session = $this->storeCloud($data['phone_number_id'], $data['waba_id'], $token, $profile, $data['name'] ?? null, [
            'onboarding'  => 'embedded_signup',
            'coexistence' => $coexistence,
        ]);

        $warnings = [];

        $subscription = $this->subscribe($client);

        if (! $subscription['subscribed']) {
            $warnings[] = trans('whatsapp::app.connections.cloud_not_subscribed');
        }

        $registered = $coexistence || strtoupper((string) ($profile['platform_type'] ?? '')) === 'CLOUD_API';
        $pin = null;

        if (! $registered) {
            $pin = $data['pin'] ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            try {
                $client->register($pin);
                $registered = true;
            } catch (CloudApiException $exception) {
                $pin = null;
                $warnings[] = trans('whatsapp::app.connections.es_register_failed', ['reason' => $exception->getMessage()]);
            }
        }

        // The PIN is kept (encrypted, never shown) because Meta asks for it again if the number is re-registered.
        $this->remember($session, array_filter(['pin' => $pin]) + [
            'registered'       => $registered,
            'webhook_override' => $subscription['override'],
        ]);

        return response()->json([
            'data'       => $this->cloudData($session),
            'subscribed' => $subscription['subscribed'],
            'registered' => $registered,
            'warnings'   => $warnings,
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $extra
     */
    private function storeCloud(string $phoneNumberId, string $wabaId, string $token, array $profile, ?string $name, array $extra = []): Session
    {
        $session = Session::query()->firstOrNew(['channel' => 'cloud', 'external_id' => $phoneNumberId]);

        $session->name = trim((string) $name) ?: ($session->name ?: (string) ($profile['verified_name'] ?? $phoneNumberId));
        $session->phone = preg_replace('/\D/', '', (string) ($profile['display_phone_number'] ?? '')) ?: null;
        $session->status = 'WORKING';
        $session->status_changed_at = now();
        $session->credentials = $extra + [
            'access_token'  => $token,
            'waba_id'       => $wabaId,
            'verified_name' => $profile['verified_name'] ?? null,
            'quality'       => $profile['quality_rating'] ?? null,
            'name_status'   => $profile['name_status'] ?? null,
            'checked_at'    => now()->toIso8601String(),
        ];
        $session->save();

        return $session;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function remember(Session $session, array $values): void
    {
        $session->credentials = $values + (array) $session->credentials;
        $session->save();
    }

    /**
     * Subscribes the app to the account's webhooks, pointing them at this installation when
     * possible (one Meta app serves several CRM installations); falls back to the app's own
     * webhook URL when Meta refuses the override.
     *
     * @return array{subscribed: bool, override: bool}
     */
    private function subscribe(CloudClient $client): array
    {
        $callback = route('admin.whatsapp.cloud.webhook');
        $verify = (string) config('whatsapp.cloud.verify_token');

        if (config('whatsapp.cloud.webhook_override', true) && $verify !== '' && str_starts_with($callback, 'https://')) {
            try {
                $client->subscribeApp($callback, $verify);

                return ['subscribed' => true, 'override' => true];
            } catch (CloudApiException) {
                // Tried again below without the override.
            }
        }

        try {
            $client->subscribeApp();

            return ['subscribed' => true, 'override' => false];
        } catch (CloudApiException) {
            return ['subscribed' => false, 'override' => false];
        }
    }

    public function refreshCloud(int $id): JsonResponse
    {
        $session = Session::query()->where('channel', 'cloud')->findOrFail($id);

        return $this->guardCloud(function () use ($session) {
            $profile = CloudClient::forSession($session)->profile();

            $credentials = (array) $session->credentials;
            $credentials['verified_name'] = $profile['verified_name'] ?? ($credentials['verified_name'] ?? null);
            $credentials['quality'] = $profile['quality_rating'] ?? null;
            $credentials['name_status'] = $profile['name_status'] ?? null;
            $credentials['checked_at'] = now()->toIso8601String();

            $session->credentials = $credentials;
            $session->status = 'WORKING';
            $session->status_changed_at = now();
            $session->save();

            return ['data' => $this->cloudData($session)];
        });
    }

    /**
     * Drops the token and stops the number; the conversations and messages stay. The app
     * also stops receiving the account's webhooks, unless another connected number shares it.
     */
    public function disconnectCloud(int $id): JsonResponse
    {
        $session = Session::query()->where('channel', 'cloud')->findOrFail($id);

        $credentials = (array) $session->credentials;
        $wabaId = (string) ($credentials['waba_id'] ?? '');
        $unsubscribed = null;

        if (! empty($credentials['access_token']) && $wabaId !== '') {
            $shared = Session::query()->where('channel', 'cloud')->whereKeyNot($session->id)->get()
                ->contains(fn (Session $other) => ! empty($other->credentials['access_token']) && (string) ($other->credentials['waba_id'] ?? '') === $wabaId);

            if (! $shared) {
                try {
                    CloudClient::forSession($session)->unsubscribeApp();
                    $unsubscribed = true;
                } catch (CloudApiException) {
                    $unsubscribed = false;
                }
            }
        }

        unset($credentials['access_token'], $credentials['pin']);

        $session->credentials = $credentials;
        $session->status = 'STOPPED';
        $session->status_changed_at = now();
        $session->save();

        return response()->json(['data' => $this->cloudData($session), 'unsubscribed' => $unsubscribed]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cloudData(Session $session): array
    {
        $credentials = (array) $session->credentials;

        return [
            'id'            => $session->id,
            'name'          => $session->name,
            'phone'         => $session->phone,
            'status'        => $session->status,
            'verified_name' => $credentials['verified_name'] ?? null,
            'quality'       => $credentials['quality'] ?? null,
            'name_status'   => $credentials['name_status'] ?? null,
            'checked_at'    => $credentials['checked_at'] ?? null,
            'connected'     => ! empty($credentials['access_token']),
            'phone_number_id' => $session->external_id,
            'waba_id'       => $credentials['waba_id'] ?? null,
            'embedded'      => ($credentials['onboarding'] ?? null) === 'embedded_signup',
            'coexistence'   => (bool) ($credentials['coexistence'] ?? false),
            'registered'    => $credentials['registered'] ?? null,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function guardCloud(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (CloudApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function action(int $id, string $action): JsonResponse
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        $session = Session::query()->where('channel', 'waha')->findOrFail($id);

        return $this->guard(function () use ($session, $action) {
            WahaClient::fromConfig()->controlSession($session->external_id, $action);

            return [];
        });
    }

    public function qr(int $id): JsonResponse
    {
        $session = Session::query()->where('channel', 'waha')->findOrFail($id);

        return $this->guard(fn () => WahaClient::fromConfig()->qrCode($session->external_id));
    }

    public function code(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $phone = preg_replace('/\D+/', '', $data['phone']);

        abort_unless(strlen($phone) >= 10 && strlen($phone) <= 15, 422, trans('whatsapp::app.connections.invalid_phone'));

        $session = Session::query()->where('channel', 'waha')->findOrFail($id);

        return $this->guard(fn () => ['code' => WahaClient::fromConfig()->requestPairingCode($session->external_id, $phone)]);
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function guard(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (WahaException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    private function phone(mixed $id): ?string
    {
        if (! is_string($id)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $id)[0]);

        return $digits === '' ? null : $digits;
    }
}
