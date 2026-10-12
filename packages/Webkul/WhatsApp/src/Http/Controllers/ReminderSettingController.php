<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Webkul\Lead\Models\Pipeline;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Models\ReminderSetting;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Support\AppointmentReminders;

class ReminderSettingController extends Controller
{
    private const ACTIVITY_TYPES = ['meeting', 'call', 'lunch'];

    public function show(): JsonResponse
    {
        $settings = ReminderSetting::query()->first() ?? new ReminderSetting(['enabled' => false, 'offsets' => [1440, 120], 'activity_type' => 'meeting']);

        $pipelines = Pipeline::query()->with(['stages' => fn ($query) => $query->orderBy('sort_order')])->orderBy('id')->get();
        $several = $pipelines->count() > 1;

        return response()->json([
            'data' => [
                'enabled'           => (bool) $settings->enabled,
                'stage_id'          => $settings->stage_id,
                'keep_stage_ids'    => array_map('intval', (array) ($settings->keep_stage_ids ?? [])),
                'activity_type'     => $settings->activity_type ?: 'meeting',
                'template_name'     => $settings->template_name,
                'template_language' => $settings->template_language,
                'variables'         => array_values((array) ($settings->variables ?? [])),
                'offsets'           => array_map('intval', (array) ($settings->offsets ?? [])),
            ],
            'stages' => $pipelines->flatMap(fn ($pipeline) => $pipeline->stages->map(fn ($stage) => [
                'id'   => $stage->id,
                'name' => $several ? $pipeline->name.' · '.$stage->name : $stage->name,
            ]))->values(),
            'sources'        => AppointmentReminders::SOURCES,
            'offset_options' => AppointmentReminders::OFFSETS,
            'activity_types' => self::ACTIVITY_TYPES,
            'has_number'     => $this->session() !== null,
        ]);
    }

    /**
     * The approved templates the reminders can use (the ones the Inbox can fill and send).
     */
    public function templates(): JsonResponse
    {
        $session = $this->session();

        if ($session === null) {
            return response()->json(['data' => [], 'message' => trans('whatsapp::app.connections.reminder_no_number')], 200);
        }

        try {
            $templates = collect(CloudTemplateController::approved($session))
                ->filter(fn ($template) => $template['supported'])
                ->values();
        } catch (CloudApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json(['data' => $templates]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'           => ['required', 'boolean'],
            'stage_id'          => ['nullable', 'integer', 'exists:lead_pipeline_stages,id'],
            'keep_stage_ids'    => ['nullable', 'array', 'max:30'],
            'keep_stage_ids.*'  => ['integer', 'exists:lead_pipeline_stages,id'],
            'activity_type'     => ['required', Rule::in(self::ACTIVITY_TYPES)],
            'template_name'     => ['nullable', 'string', 'max:512'],
            'template_language' => ['nullable', 'string', 'max:20'],
            'variables'         => ['nullable', 'array', 'max:20'],
            'variables.*'       => ['string', Rule::in(AppointmentReminders::SOURCES)],
            'offsets'           => ['nullable', 'array', 'max:7'],
            'offsets.*'         => ['integer', Rule::in(AppointmentReminders::OFFSETS)],
        ]);

        if ($data['enabled']) {
            abort_if(empty($data['stage_id']) || empty($data['template_name']) || empty($data['offsets']), 422, trans('whatsapp::app.connections.reminder_incomplete'));

            $this->assertVariablesFit($data);
        }

        $settings = ReminderSetting::query()->first() ?? new ReminderSetting;

        $settings->fill([
            'enabled'           => $data['enabled'],
            'stage_id'          => $data['stage_id'] ?? null,
            'keep_stage_ids'    => array_values(array_unique(array_map('intval', $data['keep_stage_ids'] ?? []))),
            'activity_type'     => $data['activity_type'],
            'template_name'     => $data['template_name'] ?? null,
            'template_language' => $data['template_language'] ?? null,
            'variables'         => array_values($data['variables'] ?? []),
            'offsets'           => array_values(array_unique(array_map('intval', $data['offsets'] ?? []))),
        ])->save();

        return response()->json(['saved' => true]);
    }

    /**
     * The template needs exactly one source per variable. Skipped when the template list
     * cannot be loaded, so a Meta outage does not block saving.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertVariablesFit(array $data): void
    {
        $session = $this->session();

        if ($session === null) {
            return;
        }

        try {
            $template = collect(CloudTemplateController::approved($session))
                ->first(fn ($item) => $item['name'] === $data['template_name'] && $item['language'] === ($data['template_language'] ?? ''));
        } catch (CloudApiException $exception) {
            return;
        }

        abort_if($template === null || ! $template['supported'], 422, trans('whatsapp::app.inbox.template_unavailable'));
        abort_if(count($data['variables'] ?? []) !== $template['variables'], 422, trans('whatsapp::app.connections.reminder_variables'));
    }

    private function session(): ?Session
    {
        $setting = ReminderSetting::query()->first();

        return Session::query()
            ->where('channel', 'cloud')
            ->where('status', 'WORKING')
            ->when($setting?->session_id, fn ($query) => $query->whereKey($setting->session_id))
            ->orderBy('id')
            ->first();
    }
}
