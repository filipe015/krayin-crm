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
use Webkul\WhatsApp\Models\Session;

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
                'connections' => route('admin.whatsapp.connections.index'),
                'inbox'       => route('admin.whatsapp.inbox.index'),
            ],
            'categories' => self::CATEGORIES,
            'languages'  => self::LANGUAGES,
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
        ], [
            'name.regex'     => trans('whatsapp::app.templates.invalid_name'),
            'language.regex' => trans('whatsapp::app.templates.invalid_language'),
        ]);

        $session = $this->numbers()->firstWhere('id', (int) $data['session_id']);

        abort_if($session === null, 404, trans('whatsapp::app.templates.no_number'));

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

        try {
            $created = CloudClient::forSession($session)->createTemplate($data['name'], $data['category'], $data['language'], $components);
        } catch (CloudApiException $exception) {
            return response()->json(['message' => trans('whatsapp::app.templates.create_failed', ['reason' => $exception->getMessage()])], 422);
        }

        Cache::forget('whatsapp.cloud.templates.'.$session->id);

        return response()->json(['data' => [
            'id'       => (string) ($created['id'] ?? ''),
            'name'     => $data['name'],
            'language' => $data['language'],
            'status'   => strtoupper((string) ($created['status'] ?? 'PENDING')),
            'category' => strtoupper((string) ($created['category'] ?? $data['category'])),
        ]], 201);
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
