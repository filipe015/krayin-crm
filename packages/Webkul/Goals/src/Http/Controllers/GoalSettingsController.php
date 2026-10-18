<?php

namespace Webkul\Goals\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Webkul\Goals\Http\Requests\AlertsRequest;
use Webkul\Goals\Http\Requests\PlanRequest;
use Webkul\Goals\Http\Requests\SettingsRequest;
use Webkul\Goals\Support\GoalAlertSettings;
use Webkul\Goals\Support\GoalPlanService;
use Webkul\Goals\Support\GoalScreenData;
use Webkul\Goals\Support\GoalSettingsService;

/**
 * Configurações → Metas. Thin on purpose: the rules live in the Support classes.
 */
class GoalSettingsController extends Controller
{
    public function index(): View
    {
        return view('goals::settings.index', [
            'urls' => [
                'data'          => route('admin.goals.settings.data'),
                'preview'       => route('admin.goals.settings.preview'),
                'plan'          => route('admin.goals.settings.plan'),
                'copy'          => route('admin.goals.settings.copy'),
                'workingDays'   => route('admin.goals.settings.working_days'),
                'settings'      => route('admin.goals.settings.save'),
                'alerts'        => route('admin.goals.settings.alerts'),
                'history'       => route('admin.goals.settings.history'),
            ],
            'month'   => now()->format('Y-m'),
            'canEdit' => bouncer()->hasPermission('settings.other_settings.goals.edit'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json(GoalScreenData::for($this->month($request)));
    }

    public function preview(PlanRequest $request): JsonResponse
    {
        return $this->guard(fn () => response()->json(GoalPlanService::preview($request->validated(), $request->input('fixed', []))));
    }

    public function savePlan(PlanRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            GoalPlanService::save($request->input('month'), (string) $request->input('funnel', ''), $request->validated(), $request->input('fixed', []), $this->userId());

            return response()->json(GoalScreenData::for($request->input('month')));
        });
    }

    public function deletePlan(Request $request): JsonResponse
    {
        $month = $this->month($request);

        GoalPlanService::delete($month, (string) $request->input('funnel', ''), $this->userId());

        return response()->json(GoalScreenData::for($month));
    }

    public function copyMonth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to'   => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', 'different:from'],
        ]);

        return $this->guard(function () use ($data) {
            $copied = GoalPlanService::copyMonth($data['from'], $data['to'], $this->userId());

            return response()->json(['copied' => $copied] + GoalScreenData::for($data['to']));
        });
    }

    public function saveWorkingDays(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'days'  => ['required', 'array', 'min:1'],
            'days.*' => ['date_format:Y-m-d'],
        ]);

        return $this->guard(function () use ($data) {
            GoalPlanService::saveWorkingDays($data['month'], $data['days'], $this->userId());

            return response()->json(GoalScreenData::for($data['month']));
        });
    }

    public function saveSettings(SettingsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            GoalSettingsService::save($request->input('step_rules', []), $request->input('weekdays', []), $this->userId());

            return response()->json(['settings' => GoalSettingsService::get()]);
        });
    }

    public function saveAlerts(AlertsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            GoalAlertSettings::save($request->input('rules', []), $request->boolean('enabled'), $this->userId());

            return response()->json(['alerts' => GoalAlertSettings::get()]);
        });
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'page'  => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(GoalScreenData::history($data['month'] ?? null, (int) ($data['page'] ?? 1)));
    }

    /**
     * The services refuse what is not valid (a safety net behind the form requests): that is a 422.
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (InvalidArgumentException) {
            return response()->json(['message' => trans('goals::app.validation.failed')], 422);
        }
    }

    private function month(Request $request): string
    {
        return $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['month'];
    }

    private function userId(): int
    {
        return (int) auth()->guard('user')->id();
    }
}
