<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\LeadSetting;
use Webkul\WhatsApp\Support\AutoLead;
use Webkul\WhatsApp\Support\LeadCreationSettings;

/**
 * Configurações → Criação de leads pelo WhatsApp: whether new conversations open a lead by
 * themselves (manual | ads | all) and where. One row per installation; until it is saved the
 * WHATSAPP_AUTO_LEAD* env applies (and fills the form).
 */
class LeadSettingController extends Controller
{
    public function index(AutoLead $autoLead): View
    {
        $settings = LeadCreationSettings::current();

        $pipelineId = $settings['pipeline_id'];
        $stageId = $settings['stage_id'];

        // Not saved yet and the env points somewhere (possibly a stage by name): show where
        // the automatic lead goes today, so saving the screen keeps it there.
        if (! $settings['saved'] && ($pipelineId !== null || $stageId !== null || $settings['stage'] !== null)) {
            [$pipeline, $stage] = rescue(fn () => $autoLead->placement($settings), [null, null], false);

            $pipelineId = $pipeline?->id;
            $stageId = $stage?->id;
        }

        return view('whatsapp::lead-settings.index', [
            'settings' => [
                'mode'        => $settings['mode'],
                'pipeline_id' => $pipelineId,
                'stage_id'    => $stageId,
                'source'      => (string) $settings['source'],
                'tags'        => implode(', ', $settings['tags']),
                'user_id'     => $settings['user_id'],
            ],
            'saved'     => $settings['saved'],
            'canEdit'   => bouncer()->hasPermission('settings.other_settings.whatsapp-leads.edit'),
            'pipelines' => self::pipelines(),
            'users'     => User::query()->where('status', 1)->orderBy('name')->get(['id', 'name']),
            'urls'      => ['save' => route('admin.whatsapp.lead-settings.update')],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // The legacy "off" is accepted as "manual"; anything else unknown is refused.
        if (strtolower(trim((string) $request->input('mode'))) === 'off') {
            $request->merge(['mode' => 'manual']);
        }

        $data = $request->validate([
            'mode'        => ['required', Rule::in(LeadCreationSettings::MODES)],
            'pipeline_id' => ['nullable', 'integer', 'required_with:stage_id', Rule::exists('lead_pipelines', 'id')],
            'stage_id'    => ['nullable', 'integer', Rule::exists('lead_pipeline_stages', 'id')],
            'source'      => ['nullable', 'string', 'max:100'],
            'tags'        => ['nullable', 'string', 'max:500'],
            'user_id'     => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        if (! empty($data['stage_id'])) {
            $belongs = DB::table('lead_pipeline_stages')
                ->where('id', (int) $data['stage_id'])
                ->where('lead_pipeline_id', (int) $data['pipeline_id'])
                ->where(fn ($query) => $query->whereNull('code')->orWhereNotIn('code', ['won', 'lost']))
                ->exists();

            abort_unless($belongs, 422, trans('whatsapp::app.lead_settings.stage_invalid'));
        }

        $tags = implode(', ', array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['tags'] ?? '')))))));

        $setting = LeadSetting::query()->orderBy('id')->first() ?? new LeadSetting;

        $setting->fill([
            'mode'        => $data['mode'],
            'pipeline_id' => $data['pipeline_id'] ?? null,
            'stage_id'    => $data['stage_id'] ?? null,
            'source'      => filled($data['source'] ?? null) ? trim($data['source']) : null,
            'tags'        => $tags !== '' ? mb_substr($tags, 0, 500) : null,
            'user_id'     => $data['user_id'] ?? null,
        ])->save();

        LeadCreationSettings::forget();

        return response()->json(['saved' => true]);
    }

    /**
     * @return list<array{id: int, name: string, is_default: bool, stages: list<array{id: int, name: string}>}>
     */
    private static function pipelines(): array
    {
        $stages = DB::table('lead_pipeline_stages')
            ->where(fn ($query) => $query->whereNull('code')->orWhereNotIn('code', ['won', 'lost']))
            ->orderBy('sort_order')
            ->get(['id', 'name', 'lead_pipeline_id'])
            ->groupBy('lead_pipeline_id');

        return DB::table('lead_pipelines')->orderBy('name')->get(['id', 'name', 'is_default'])->map(fn ($pipeline) => [
            'id'         => (int) $pipeline->id,
            'name'       => $pipeline->name,
            'is_default' => (bool) $pipeline->is_default,
            'stages'     => ($stages[$pipeline->id] ?? collect())->map(fn ($stage) => ['id' => (int) $stage->id, 'name' => $stage->name])->values()->all(),
        ])->all();
    }
}
