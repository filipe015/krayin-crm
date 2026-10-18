<?php

namespace Webkul\Goals\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Goals\Support\GoalProgress;

/**
 * The "Metas" tracking screen: how the month is going against the plan set in Configurações → Metas.
 */
class GoalTrackingController extends Controller
{
    public function index(): View
    {
        return view('goals::tracking.index', [
            'urls' => [
                'data'     => route('admin.goals.data'),
                'settings' => route('admin.goals.settings.index'),
            ],
            'month'   => now()->format('Y-m'),
            'canEdit' => bouncer()->hasPermission('settings.other_settings.goals'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month'  => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'funnel' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(GoalProgress::for($data['month'] ?? CarbonImmutable::now()->format('Y-m'), (string) ($data['funnel'] ?? '')));
    }
}
