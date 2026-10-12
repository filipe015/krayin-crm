<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\AgendaSetting;

class AgendaSettingController extends Controller
{
    public function index(): View
    {
        $setting = AgendaSetting::current();

        return view('whatsapp::agenda.settings', [
            'settings' => [
                'singular'            => $setting->singular,
                'plural'              => $setting->plural,
                'gender'              => $setting->gender,
                'responsible'         => $setting->responsible,
                'responsible_plural'  => $setting->responsible_plural,
                'default_duration'    => (int) $setting->default_duration,
                'day_start'           => (int) $setting->day_start,
                'day_end'             => (int) $setting->day_end,
                'distribution_mode'   => $setting->distribution_mode,
                'distribution_user_ids' => array_map('intval', (array) ($setting->distribution_user_ids ?? [])),
            ],
            'users'     => User::query()->where('status', 1)->orderBy('name')->get(['id', 'name']),
            'durations' => AgendaController::DURATIONS,
            'urls'      => ['save' => route('admin.agenda.settings.update')],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'singular'              => ['required', 'string', 'max:40'],
            'plural'                => ['required', 'string', 'max:40'],
            'gender'                => ['required', Rule::in(['m', 'f'])],
            'responsible'           => ['required', 'string', 'max:40'],
            'responsible_plural'    => ['required', 'string', 'max:40'],
            'default_duration'      => ['required', 'integer', Rule::in(AgendaController::DURATIONS)],
            'day_start'             => ['required', 'integer', 'between:0,23'],
            'day_end'               => ['required', 'integer', 'between:1,24', 'gt:day_start'],
            'distribution_mode'     => ['required', Rule::in(AgendaSetting::MODES)],
            'distribution_user_ids' => ['nullable', 'array', 'max:100'],
            'distribution_user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        $data['distribution_user_ids'] = array_values(array_unique(array_map('intval', $data['distribution_user_ids'] ?? [])));

        abort_if(
            $data['distribution_mode'] !== 'manual' && $data['distribution_user_ids'] === [],
            422,
            trans('whatsapp::app.agenda_settings.pool_required'),
        );

        $setting = AgendaSetting::query()->first() ?? new AgendaSetting;
        $setting->fill($data)->save();

        return response()->json(['saved' => true]);
    }
}
