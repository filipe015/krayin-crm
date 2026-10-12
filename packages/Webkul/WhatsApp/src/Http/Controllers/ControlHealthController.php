<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Support\VoiceTranscoder;

/**
 * What the super-admin panel reads from every installation: health and usage counters,
 * never customer data. It answers only to the key set in WHATSAPP_CONTROL_KEY.
 */
class ControlHealthController extends Controller
{
    public const MODULE_VERSION = '2026.10.3';

    public const SCHEDULER_CACHE_KEY = 'whatsapp.scheduler.last_run';

    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('whatsapp.control_key');
        $given = (string) $request->header('X-Control-Key', '');

        // Not configured: behave as if the route did not exist.
        abort_if(strlen($expected) < 24, 404);
        abort_unless(hash_equals($expected, $given), 401);

        $monthStart = now()->startOfMonth();

        $month = Message::query()
            ->where('created_at', '>=', $monthStart)
            ->selectRaw("SUM(direction = 'inbound') as messages_in")
            ->selectRaw("SUM(direction = 'outbound' AND status <> 'scheduled') as messages_out")
            ->selectRaw("SUM(direction = 'outbound' AND message_type = 'template' AND status <> 'scheduled') as templates_out")
            ->first();

        $lastRun = Cache::get(self::SCHEDULER_CACHE_KEY);

        return response()->json([
            'ok'             => true,
            'module_version' => self::MODULE_VERSION,
            'app_version'    => config('whatsapp.app_version'),
            'time'           => now()->toIso8601String(),
            'scheduler'      => [
                'last_run'    => $lastRun,
                'seconds_ago' => $lastRun ? max(0, now()->getTimestamp() - \Illuminate\Support\Carbon::parse($lastRun)->getTimestamp()) : null,
            ],
            'numbers' => Session::query()->orderBy('id')->get(['channel', 'name', 'phone', 'status', 'credentials'])
                ->map(fn ($session) => [
                    'channel' => $session->channel,
                    'name'    => $session->name,
                    'phone'   => $session->phone,
                    'status'  => $session->status,
                    'quality' => $session->channel === 'cloud' ? ($session->credentials['quality'] ?? null) : null,
                ])->values(),
            'usage' => [
                'month'             => $monthStart->format('Y-m'),
                'messages_in'       => (int) ($month->messages_in ?? 0),
                'messages_out'      => (int) ($month->messages_out ?? 0),
                'templates_out'     => (int) ($month->templates_out ?? 0),
                'failed_24h'        => Message::query()->where('direction', 'outbound')->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
                'scheduled_pending' => Message::query()->where('status', 'scheduled')->count(),
            ],
            'totals' => [
                'users' => (int) DB::table('users')->count(),
                'leads' => (int) DB::table('leads')->count(),
            ],
            'features' => [
                'ffmpeg' => VoiceTranscoder::available(),
            ],
        ]);
    }
}
