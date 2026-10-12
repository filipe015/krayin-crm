<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\Client\Factory;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Channels\Cloud\CloudClient;
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
                'cloudRefresh'    => route('admin.whatsapp.connections.cloud.refresh', ['id' => '__ID__']),
                'cloudDisconnect' => route('admin.whatsapp.connections.cloud.disconnect', ['id' => '__ID__']),
                'rules'           => route('admin.whatsapp.button-rules.list'),
                'ruleStore'       => route('admin.whatsapp.button-rules.store'),
                'ruleDelete'      => route('admin.whatsapp.button-rules.delete', ['id' => '__ID__']),
            ],
            'cloudWebhook' => route('admin.whatsapp.cloud.webhook'),
            'cloudVerifyConfigured' => filled(config('whatsapp.cloud.verify_token')),
            'cloudSecretConfigured' => filled(config('whatsapp.cloud.app_secret')),
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

        $session = Session::query()->firstOrNew(['channel' => 'cloud', 'external_id' => $data['phone_number_id']]);

        $session->name = trim((string) ($data['name'] ?? '')) ?: (string) ($profile['verified_name'] ?? $data['phone_number_id']);
        $session->phone = preg_replace('/\D/', '', (string) ($profile['display_phone_number'] ?? '')) ?: null;
        $session->status = 'WORKING';
        $session->status_changed_at = now();
        $session->credentials = [
            'access_token'  => $data['access_token'],
            'waba_id'       => $data['waba_id'],
            'verified_name' => $profile['verified_name'] ?? null,
            'quality'       => $profile['quality_rating'] ?? null,
            'name_status'   => $profile['name_status'] ?? null,
            'checked_at'    => now()->toIso8601String(),
        ];
        $session->save();

        // Without this subscription Meta does not deliver the WABA's webhooks to the app.
        $subscribed = true;

        try {
            $client->subscribeApp();
        } catch (CloudApiException) {
            $subscribed = false;
        }

        return response()->json(['data' => $this->cloudData($session), 'subscribed' => $subscribed], 201);
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
     * Drops the token and stops the number; the conversations and messages stay.
     */
    public function disconnectCloud(int $id): JsonResponse
    {
        $session = Session::query()->where('channel', 'cloud')->findOrFail($id);

        $credentials = (array) $session->credentials;
        unset($credentials['access_token']);

        $session->credentials = $credentials;
        $session->status = 'STOPPED';
        $session->status_changed_at = now();
        $session->save();

        return response()->json(['data' => $this->cloudData($session)]);
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
