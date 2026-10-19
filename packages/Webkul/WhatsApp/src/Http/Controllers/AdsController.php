<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Webkul\WhatsApp\Api\Support\AdsOverview;
use Webkul\WhatsApp\Api\Support\AdsPerformance;

/**
 * Anúncios: Meta Ads spend against the leads, by campaign, ad set or ad (matched by UTM).
 */
class AdsController extends Controller
{
    public function index(): View
    {
        return view('whatsapp::ads.index', [
            'urls' => [
                'overview' => route('admin.ads.overview'),
                'settings' => route('admin.ads.settings'),
            ],
            'start' => now()->startOfMonth()->format('Y-m-d'),
            'end' => now()->format('Y-m-d'),
            'canEdit' => bouncer()->hasPermission('ads'),
        ]);
    }

    /**
     * The dashboard for a period (start and end, both included) or for one month; this month by default.
     */
    public function overview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'start' => ['nullable', 'date_format:Y-m-d', 'required_with:end'],
            'end'   => ['nullable', 'date_format:Y-m-d', 'required_with:start', 'after_or_equal:start'],
        ]);

        $zone = config('app.timezone');

        if (isset($data['start'])) {
            $from = Carbon::createFromFormat('Y-m-d', $data['start'], $zone)->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $data['end'], $zone)->startOfDay();

            // A year of days is already a long chart; beyond that it is not a dashboard.
            abort_if($from->diffInDays($to) > 366, 422);

            return response()->json(app(AdsOverview::class)->range($from, $to));
        }

        $month = Carbon::createFromFormat('Y-m-d', ($data['month'] ?? now()->format('Y-m')).'-01', $zone)->startOfDay();

        return response()->json(app(AdsOverview::class)->month($month));
    }

    /**
     * Percentage of tax added on top of the investment.
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['tax_percent' => ['required', 'numeric', 'min:0', 'max:100']]);

        AdsOverview::saveTaxPercent((float) $data['tax_percent']);

        return response()->json(['tax_percent' => AdsOverview::taxPercent()]);
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
