<?php

namespace Webkul\WhatsApp\Channels\Cloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Webkul\WhatsApp\Models\Session;

/**
 * Thin client of the WhatsApp Business Cloud API (Meta Graph API) for one phone number.
 */
final class CloudClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly ?string $wabaId = null,
        private readonly string $version = 'v23.0',
        private readonly int $timeout = 15,
    ) {}

    public static function forSession(Session $session): self
    {
        $credentials = (array) $session->credentials;
        $token = (string) ($credentials['access_token'] ?? '');

        if ($token === '') {
            throw new CloudApiException('This WhatsApp number has no access token configured.');
        }

        return new self(
            app(Factory::class),
            $token,
            $session->external_id,
            isset($credentials['waba_id']) ? (string) $credentials['waba_id'] : null,
            (string) config('whatsapp.cloud.graph_version', 'v23.0'),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    public function profile(): array
    {
        return $this->request('GET', $this->phoneNumberId, [
            'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,name_status,platform_type,throughput',
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    public function sendText(string $to, string $body): array
    {
        return $this->request('POST', $this->phoneNumberId.'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    public function sendTemplate(string $to, string $name, string $language, array $components = []): array
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->request('POST', $this->phoneNumberId.'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => $template,
        ]);
    }

    /**
     * Uploads a file and returns its media id.
     *
     * @throws CloudApiException
     */
    public function uploadMedia(string $mime, string $filename, string $contents): string
    {
        try {
            $response = $this->authorized()
                ->timeout(60)
                ->attach('file', $contents, $filename, ['Content-Type' => $mime])
                ->post($this->url($this->phoneNumberId.'/media'), ['messaging_product' => 'whatsapp', 'type' => $mime]);
        } catch (ConnectionException) {
            throw new CloudApiException('The WhatsApp server did not answer in time.', null, true);
        }

        $json = $this->unwrap($response);
        $id = $json['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new CloudApiException('WhatsApp did not accept the file.');
        }

        return $id;
    }

    /**
     * @param  'image'|'audio'|'video'|'document'  $type
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    public function sendMedia(string $to, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): array
    {
        $media = ['id' => $mediaId];

        if ($caption !== null && $caption !== '' && $type !== 'audio') {
            $media['caption'] = $caption;
        }

        if ($type === 'document' && $filename !== null && $filename !== '') {
            $media['filename'] = $filename;
        }

        return $this->request('POST', $this->phoneNumberId.'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $media,
        ]);
    }

    /**
     * Templates of the WhatsApp Business Account (all statuses; the caller filters).
     *
     * @return list<array<string, mixed>>
     *
     * @throws CloudApiException
     */
    public function templates(): array
    {
        if ($this->wabaId === null || $this->wabaId === '') {
            throw new CloudApiException('This WhatsApp number has no business account id configured.');
        }

        $json = $this->request('GET', $this->wabaId.'/message_templates', [
            'fields' => 'name,language,status,category,components',
            'limit'  => 200,
        ]);

        return array_values((array) ($json['data'] ?? []));
    }

    /**
     * Lets the WABA deliver webhooks to the app.
     *
     * @throws CloudApiException
     */
    public function subscribeApp(): void
    {
        if ($this->wabaId === null || $this->wabaId === '') {
            return;
        }

        $this->request('POST', $this->wabaId.'/subscribed_apps');
    }

    /**
     * @return array{url: string, mime_type: ?string}
     *
     * @throws CloudApiException
     */
    public function mediaInfo(string $mediaId): array
    {
        $json = $this->request('GET', $mediaId);
        $url = $json['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new CloudApiException('The file is no longer available on WhatsApp.');
        }

        return ['url' => $url, 'mime_type' => isset($json['mime_type']) ? (string) $json['mime_type'] : null];
    }

    /**
     * Streams a media file (the URL from mediaInfo() needs the same bearer token).
     *
     * @throws CloudApiException
     */
    public function download(string $url): Response
    {
        try {
            $response = $this->authorized()->timeout(60)->withOptions(['stream' => true])->get($url);
        } catch (ConnectionException) {
            throw new CloudApiException('The WhatsApp server did not answer in time.', null, true);
        }

        if ($response->failed()) {
            throw new CloudApiException('The file could not be downloaded from WhatsApp.', $response->status());
        }

        return $response;
    }

    /**
     * Id of the message WhatsApp accepted (the "wamid").
     *
     * @param  array<string, mixed>  $response
     */
    public static function messageId(array $response): ?string
    {
        $id = $response['messages'][0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $data  Query string for GET, JSON body otherwise.
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    private function request(string $method, string $path, array $data = []): array
    {
        try {
            $pending = $this->authorized()->acceptJson()->timeout($this->timeout);

            $response = $method === 'GET'
                ? $pending->get($this->url($path), $data)
                : $pending->post($this->url($path), $data);
        } catch (ConnectionException) {
            throw new CloudApiException('The WhatsApp server did not answer in time.', null, true);
        }

        return $this->unwrap($response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    private function unwrap(Response $response): array
    {
        if ($response->failed()) {
            $error = $response->json('error');
            $code = is_array($error) && isset($error['code']) ? (int) $error['code'] : null;

            throw new CloudApiException(self::describe($response), $response->status(), false, $code);
        }

        return (array) $response->json();
    }

    private function authorized(): PendingRequest
    {
        return $this->http->withToken($this->accessToken);
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version.'/'.ltrim($path, '/');
    }

    private static function describe(Response $response): string
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return 'WhatsApp answered '.$response->status().'.';
        }

        $message = (string) ($error['error_data']['details'] ?? $error['message'] ?? 'Unknown error');
        $code = isset($error['code']) ? ' (code '.$error['code'].')' : '';

        return 'WhatsApp: '.$message.$code;
    }
}
