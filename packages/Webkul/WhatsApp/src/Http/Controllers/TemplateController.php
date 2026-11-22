<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Channels\Cloud\CloudClient;
use Webkul\WhatsApp\Models\ReminderSetting;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Models\TemplateVariableMap;
use Webkul\WhatsApp\Support\TemplateVariables;

/**
 * Message templates of an official WhatsApp number: lists them with Meta's review status
 * and creates new ones through the Graph API. Sending one is the Inbox's job
 * (CloudTemplateController).
 */
class TemplateController extends Controller
{
    public const CATEGORIES = ['UTILITY', 'MARKETING'];

    public const LANGUAGES = ['pt_BR', 'en_US', 'es', 'es_AR', 'es_MX', 'pt_PT', 'en', 'en_GB', 'fr', 'it', 'de'];

    public function index(): View
    {
        return view('whatsapp::templates.index', [
            'urls' => [
                'list'        => route('admin.whatsapp.templates.list'),
                'store'       => route('admin.whatsapp.templates.store'),
                'mapping'     => route('admin.whatsapp.templates.mapping'),
                'update'      => route('admin.whatsapp.templates.update'),
                'destroy'     => route('admin.whatsapp.templates.destroy'),
                'connections' => route('admin.whatsapp.connections.index'),
                'inbox'       => route('admin.whatsapp.inbox.index'),
            ],
            'categories' => self::CATEGORIES,
            'languages'  => self::LANGUAGES,
            'sourceOptions' => ['base' => TemplateVariables::BASE, 'custom' => TemplateVariables::customFields()],
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $numbers = $this->numbers();
        $session = $numbers->firstWhere('id', (int) $request->query('session_id')) ?? $numbers->first();

        $payload = [
            'numbers'    => $numbers->map(fn (Session $number) => ['id' => $number->id, 'name' => $number->name, 'phone' => $number->phone])->values(),
            'session_id' => $session?->id,
            'data'       => [],
        ];

        if ($session === null) {
            return response()->json($payload);
        }

        try {
            $templates = CloudClient::forSession($session)->templates();
        } catch (CloudApiException $exception) {
            return response()->json($payload + ['message' => trans('whatsapp::app.templates.list_failed', ['reason' => $exception->getMessage()])], 502);
        }

        // The Inbox reads the same list from this cache; keep it as fresh as this screen.
        Cache::put('whatsapp.cloud.templates.'.$session->id, $templates, 300);

        $payload['data'] = collect($templates)->map(fn ($template) => CloudTemplateController::describe((array) $template) + [
            'id'     => (string) ($template['id'] ?? ''),
            'examples' => self::examplesOf((array) $template),
            'sources' => TemplateVariableMap::sourcesFor((string) ($template['name'] ?? ''), (string) ($template['language'] ?? '')),
            'status' => strtoupper((string) ($template['status'] ?? '')),
            'reason' => in_array(strtoupper((string) ($template['rejected_reason'] ?? 'NONE')), ['', 'NONE'], true) ? null : (string) $template['rejected_reason'],
        ])->sortBy('name')->values();

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'name'       => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'category'   => ['required', Rule::in(self::CATEGORIES)],
            'language'   => ['required', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'header'     => ['nullable', 'string', 'max:60'],
            'body'       => ['required', 'string', 'max:1024'],
            'footer'     => ['nullable', 'string', 'max:60'],
            'examples'   => ['nullable', 'array', 'max:20'],
            'examples.*' => ['nullable', 'string', 'max:200'],
            'sources'    => ['nullable', 'array', 'max:20'],
            'sources.*'  => ['string', 'max:210', fn ($attribute, $value, $fail) => TemplateVariables::valid((string) $value) || $fail(trans('whatsapp::app.templates.invalid_source'))],
        ], [
            'name.regex'     => trans('whatsapp::app.templates.invalid_name'),
            'language.regex' => trans('whatsapp::app.templates.invalid_language'),
        ]);

        $session = $this->numbers()->firstWhere('id', (int) $data['session_id']);

        abort_if($session === null, 404, trans('whatsapp::app.templates.no_number'));

        [$components, $numbers] = $this->components($data);

        try {
            $created = CloudClient::forSession($session)->createTemplate($data['name'], $data['category'], $data['language'], $components);
        } catch (CloudApiException $exception) {
            return response()->json(['message' => trans('whatsapp::app.templates.create_failed', ['reason' => $exception->getMessage()])], 422);
        }

        Cache::forget('whatsapp.cloud.templates.'.$session->id);

        $sources = array_values($data['sources'] ?? []);

        if ($numbers !== [] && count($sources) === count($numbers)) {
            TemplateVariableMap::query()->updateOrCreate(
                ['template_name' => $data['name'], 'template_language' => $data['language']],
                ['sources' => $sources],
            );
        }

        return response()->json(['data' => [
            'id'       => (string) ($created['id'] ?? ''),
            'name'     => $data['name'],
            'language' => $data['language'],
            'status'   => strtoupper((string) ($created['status'] ?? 'PENDING')),
            'category' => strtoupper((string) ($created['category'] ?? $data['category'])),
        ]], 201);
    }

    /**
     * Validates the template text and builds Meta's components (header, body, footer).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: list<array<string, mixed>>, 1: list<int>}  components and the variable numbers found
     */
    private function components(array $data): array
    {
        $body = (string) preg_replace('/\{\{\s*(\d+)\s*\}\}/', '{{$1}}', trim($data['body']));
        $header = trim((string) ($data['header'] ?? ''));
        $footer = trim((string) ($data['footer'] ?? ''));

        // Meta wants the variables numbered {{1}}, {{2}}, ... without gaps, and a sample for each.
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);

        abort_if($numbers !== ($numbers === [] ? [] : range(1, count($numbers))), 422, trans('whatsapp::app.templates.variables_sequence'));
        abort_if(str_contains($header.$footer, '{{'), 422, trans('whatsapp::app.templates.variables_only_body'));

        $examples = array_slice(array_values(array_map(fn ($value) => trim((string) $value), (array) ($data['examples'] ?? []))), 0, count($numbers));

        abort_if(count($examples) !== count($numbers) || in_array('', $examples, true), 422, trans('whatsapp::app.templates.examples_required'));

        $components = [];

        if ($header !== '') {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
        }

        $components[] = ['type' => 'BODY', 'text' => $body] + ($examples === [] ? [] : ['example' => ['body_text' => [$examples]]]);

        if ($footer !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => $footer];
        }

        return [$components, $numbers];
    }

    /**
     * Edits a template at Meta (text, or category: Meta takes one of them per request) and
     * keeps what this screen does not edit (buttons, media header).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'id'         => ['required', 'string', 'max:64'],
            'name'       => ['required', 'string', 'max:512'],
            'language'   => ['required', 'string', 'max:20'],
            'category'   => ['required', Rule::in(self::CATEGORIES)],
            'header'     => ['nullable', 'string', 'max:60'],
            'body'       => ['required', 'string', 'max:1024'],
            'footer'     => ['nullable', 'string', 'max:60'],
            'examples'   => ['nullable', 'array', 'max:20'],
            'examples.*' => ['nullable', 'string', 'max:200'],
            'sources'    => ['nullable', 'array', 'max:20'],
            'sources.*'  => ['string', 'max:210', fn ($attribute, $value, $fail) => TemplateVariables::valid((string) $value) || $fail(trans('whatsapp::app.templates.invalid_source'))],
        ]);

        $session = $this->numbers()->firstWhere('id', (int) $data['session_id']);

        abort_if($session === null, 404, trans('whatsapp::app.templates.no_number'));

        try {
            $client = CloudClient::forSession($session);
            $current = collect($client->templates())->first(fn ($item) => (string) ($item['id'] ?? '') === $data['id'] && ($item['name'] ?? '') === $data['name']);
        } catch (CloudApiException $exception) {
            return response()->json(['message' => trans('whatsapp::app.templates.list_failed', ['reason' => $exception->getMessage()])], 502);
        }

        abort_if($current === null, 404, trans('whatsapp::app.inbox.template_unavailable'));
        abort_unless(in_array(strtoupper((string) ($current['status'] ?? '')), ['APPROVED', 'REJECTED', 'PAUSED'], true), 422, trans('whatsapp::app.templates.not_editable'));

        [$components, $numbers] = $this->components($data);

        // What this screen does not edit goes back unchanged: Meta replaces ALL components.
        $old = collect((array) ($current['components'] ?? []));
        $oldHeader = $old->first(fn ($component) => strtoupper((string) ($component['type'] ?? '')) === 'HEADER');

        if ($oldHeader !== null && strtoupper((string) ($oldHeader['format'] ?? 'TEXT')) !== 'TEXT') {
            $components = array_values(array_filter($components, fn ($component) => $component['type'] !== 'HEADER'));
            array_unshift($components, $oldHeader);
        }

        foreach ($old as $component) {
            if (! in_array(strtoupper((string) ($component['type'] ?? '')), ['HEADER', 'BODY', 'FOOTER'], true)) {
                $components[] = $component;
            }
        }

        $before = CloudTemplateController::describe((array) $current);
        $textChanged = trim((string) $before['header']) !== trim((string) ($data['header'] ?? ''))
            || trim($before['body']) !== (string) preg_replace('/\{\{\s*(\d+)\s*\}\}/', '{{$1}}', trim($data['body']))
            || trim((string) $before['footer']) !== trim((string) ($data['footer'] ?? ''));
        $categoryChanged = strtoupper((string) ($current['category'] ?? '')) !== $data['category'];

        abort_if($textChanged && $categoryChanged, 422, trans('whatsapp::app.templates.edit_one_thing'));

        if ($textChanged || $categoryChanged) {
            try {
                $client->editTemplate($data['id'], $categoryChanged ? ['category' => $data['category']] : ['components' => $components]);
            } catch (CloudApiException $exception) {
                return response()->json(['message' => trans('whatsapp::app.templates.update_failed', ['reason' => $exception->getMessage()])], 422);
            }
        }

        $sources = array_values($data['sources'] ?? []);

        if ($numbers !== [] && count($sources) === count($numbers)) {
            TemplateVariableMap::query()->updateOrCreate(
                ['template_name' => $data['name'], 'template_language' => $data['language']],
                ['sources' => $sources],
            );
        } elseif (count(TemplateVariableMap::sourcesFor($data['name'], $data['language'])) !== count($numbers)) {
            // The variables changed and the old meaning no longer fits.
            TemplateVariableMap::query()->where('template_name', $data['name'])->where('template_language', $data['language'])->delete();
        }

        Cache::forget('whatsapp.cloud.templates.'.$session->id);

        return response()->json(['saved' => true, 'sent_to_meta' => $textChanged || $categoryChanged]);
    }

    /**
     * Deletes one template (one language) at Meta. Refused while the appointment reminders
     * still use it. Meta keeps the name locked for 30 days after deleting an approved one.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'id'         => ['required', 'string', 'max:64'],
            'name'       => ['required', 'string', 'max:512'],
            'language'   => ['required', 'string', 'max:20'],
        ]);

        $session = $this->numbers()->firstWhere('id', (int) $data['session_id']);

        abort_if($session === null, 404, trans('whatsapp::app.templates.no_number'));

        $reminder = ReminderSetting::query()->first();

        abort_if(
            $reminder !== null && $reminder->enabled && $reminder->template_name === $data['name'] && $reminder->template_language === $data['language'],
            422,
            trans('whatsapp::app.templates.delete_in_use'),
        );

        try {
            CloudClient::forSession($session)->deleteTemplate($data['name'], $data['id']);
        } catch (CloudApiException $exception) {
            return response()->json(['message' => trans('whatsapp::app.templates.delete_failed', ['reason' => $exception->getMessage()])], 422);
        }

        TemplateVariableMap::query()->where('template_name', $data['name'])->where('template_language', $data['language'])->delete();
        Cache::forget('whatsapp.cloud.templates.'.$session->id);

        return response()->json(['deleted' => true]);
    }

    /**
     * The samples Meta stored for the body variables.
     *
     * @param  array<string, mixed>  $template
     * @return list<string>
     */
    private static function examplesOf(array $template): array
    {
        foreach ((array) ($template['components'] ?? []) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return array_values(array_map('strval', (array) (($component['example']['body_text'][0] ?? []))));
            }
        }

        return [];
    }

    /**
     * Saves what each variable of an existing template means in the CRM. Only the CRM
     * keeps it, so it works for approved templates and changes nothing at Meta.
     */
    public function mapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:512'],
            'language'  => ['required', 'string', 'max:20'],
            'sources'   => ['required', 'array', 'min:1', 'max:20'],
            'sources.*' => ['string', 'max:210', fn ($attribute, $value, $fail) => TemplateVariables::valid((string) $value) || $fail(trans('whatsapp::app.templates.invalid_source'))],
        ]);

        $session = $this->numbers()->first();

        abort_if($session === null, 404, trans('whatsapp::app.templates.no_number'));

        $cached = Cache::get('whatsapp.cloud.templates.'.$session->id);
        $known = collect($cached ?? CloudClient::forSession($session)->templates())
            ->map(fn ($template) => CloudTemplateController::describe((array) $template))
            ->first(fn ($item) => $item['name'] === $data['name'] && $item['language'] === $data['language']);

        abort_if($known === null, 404, trans('whatsapp::app.inbox.template_unavailable'));
        abort_if(count($data['sources']) !== $known['variables'], 422, trans('whatsapp::app.connections.reminder_variables'));

        TemplateVariableMap::query()->updateOrCreate(
            ['template_name' => $data['name'], 'template_language' => $data['language']],
            ['sources' => array_values($data['sources'])],
        );

        return response()->json(['saved' => true]);
    }

    /**
     * Connected official numbers the user may use.
     *
     * @return \Illuminate\Support\Collection<int, Session>
     */
    private function numbers()
    {
        return Session::query()
            ->where('channel', 'cloud')
            ->when(bouncer()->getAllowedSessionIds() !== null, fn ($query) => $query->whereIn('id', bouncer()->getAllowedSessionIds()))
            ->orderBy('name')
            ->get()
            ->filter(fn (Session $session) => ! empty($session->credentials['access_token']) && ! empty($session->credentials['waba_id']))
            ->values();
    }
}
