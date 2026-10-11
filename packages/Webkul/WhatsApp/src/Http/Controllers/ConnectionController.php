<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        ]);
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
