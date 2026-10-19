<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
                'stage_triggers'      => array_values((array) ($setting->stage_triggers ?? [])),
                'stage_colors'        => (object) AgendaSetting::stageColors(),
            ],
            'pipelines' => self::pipelines(),
            'colorPipelines' => self::pipelines(withClosed: true),
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
            'stage_triggers'        => ['nullable', 'array', 'max:30'],
            'stage_triggers.*.pipeline_id' => ['required', 'integer'],
            'stage_triggers.*.stage_id'    => ['required', 'integer'],
            'stage_triggers.*.kind'        => ['required', Rule::in(['appointment', 'followup'])],
            'stage_colors'                 => ['nullable', 'array', 'max:300'],
            'stage_colors.*'               => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $data['stage_colors'] = $this->validColors($data['stage_colors'] ?? []);

        $data['stage_triggers'] = $this->validTriggers($data['stage_triggers'] ?? []);

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

    /**
     * Each stage must belong to the pipeline it was chosen under, and a stage opens the card once.
     *
     * @param  list<array{pipeline_id: int, stage_id: int, kind: string}>  $triggers
     * @return list<array{pipeline_id: int, stage_id: int, kind: string}>
     */
    private function validTriggers(array $triggers): array
    {
        $valid = [];

        foreach ($triggers as $trigger) {
            $belongs = DB::table('lead_pipeline_stages')
                ->where('id', (int) $trigger['stage_id'])
                ->where('lead_pipeline_id', (int) $trigger['pipeline_id'])
                ->exists();

            abort_unless($belongs, 422, trans('whatsapp::app.agenda_settings.trigger_invalid'));

            $valid[(int) $trigger['stage_id']] = [
                'pipeline_id' => (int) $trigger['pipeline_id'],
                'stage_id'    => (int) $trigger['stage_id'],
                'kind'        => $trigger['kind'],
            ];
        }

        return array_values($valid);
    }

    /**
     * @return list<array{id: int, name: string, stages: list<array{id: int, name: string}>}>
     */
    private static function pipelines(bool $withClosed = false): array
    {
        $stages = DB::table('lead_pipeline_stages')
            ->when(! $withClosed, fn ($query) => $query->whereNotIn('code', ['won', 'lost']))
            ->orderBy('sort_order')
            ->get(['id', 'name', 'lead_pipeline_id'])
            ->groupBy('lead_pipeline_id');

        return DB::table('lead_pipelines')->orderBy('name')->get(['id', 'name'])->map(fn ($pipeline) => [
            'id'     => (int) $pipeline->id,
            'name'   => $pipeline->name,
            'stages' => ($stages[$pipeline->id] ?? collect())->map(fn ($stage) => ['id' => (int) $stage->id, 'name' => $stage->name])->values()->all(),
        ])->all();
    }

    /**
     * Only real stages keep a color, and always as #rrggbb in lower case.
     *
     * @param  array<int|string, string|null>  $colors
     * @return array<string, string>
     */
    private function validColors(array $colors): array
    {
        $stageIds = DB::table('lead_pipeline_stages')->pluck('id')->flip();
        $valid = [];

        foreach ($colors as $stageId => $color) {
            if ($color && $stageIds->has((int) $stageId)) {
                $valid[(string) (int) $stageId] = strtolower($color);
            }
        }

        return $valid;
    }
}
