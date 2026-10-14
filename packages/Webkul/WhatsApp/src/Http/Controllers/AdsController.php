<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Webkul\WhatsApp\Api\Support\AdsPerformance;

/**
 * Anúncios: Meta Ads spend against the leads, by campaign, ad set or ad (matched by UTM).
 */
class AdsController extends Controller
{
    public function index(): View
    {
        return view('whatsapp::ads.index', [
            'urls' => ['data' => route('admin.ads.data')],
            'start' => now()->subDays(29)->toDateString(),
            'end' => now()->toDateString(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end'   => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'level' => ['nullable', 'in:campaign,adset,ad'],
        ]);

        $from = Carbon::parse($data['start'], config('app.timezone'))->startOfDay();
        $to = Carbon::parse($data['end'], config('app.timezone'))->endOfDay();

        abort_if($from->diffInDays($to) > 366, 422);

        return response()->json(app(AdsPerformance::class)->report($from, $to, $data['level'] ?? 'campaign'));
    }
}
