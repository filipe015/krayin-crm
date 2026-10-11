<?php

namespace Webkul\WhatsApp\Channels\Waha;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

final class WahaClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly ?string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    public static function fromConfig(): self
    {
        return new self(app(Factory::class), config('whatsapp.waha.url'), config('whatsapp.waha.api_key'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WahaException
     */
    public function sendText(string $session, string $chatId, string $text): array
    {
        if (blank($this->baseUrl) || blank($this->apiKey)) {
            throw new WahaException('WAHA_API_URL and WAHA_API_KEY must be configured.');
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders(['X-Api-Key' => $this->apiKey])
                ->acceptJson()
                ->timeout($this->timeout)
                ->post('/api/sendText', ['session' => $session, 'chatId' => $chatId, 'text' => $text]);
        } catch (ConnectionException $exception) {
            throw new WahaException('The WAHA server did not answer in time.', null, true);
        }

        if ($response->failed()) {
            throw new WahaException(self::describe($response), $response->status());
        }

        return (array) $response->json();
    }

    /**
     * Sends a file as an image, document, voice note or video. WAHA takes the content
     * inline as base64 and converts voice and video to what WhatsApp expects.
     *
     * @param  'image'|'file'|'voice'|'video'  $kind
     * @return array<string, mixed>
     *
     * @throws WahaException
     */
    public function sendMedia(string $session, string $chatId, string $kind, string $mimetype, string $filename, string $base64, ?string $caption = null): array
    {
        $path = ['image' => '/api/sendImage', 'file' => '/api/sendFile', 'voice' => '/api/sendVoice', 'video' => '/api/sendVideo'][$kind] ?? null;

        if ($path === null) {
            throw new WahaException('Unsupported media kind.');
        }

        $body = [
            'session' => $session,
            'chatId'  => $chatId,
            'file'    => ['mimetype' => $mimetype, 'filename' => $filename, 'data' => $base64],
        ];

        if ($caption !== null && $caption !== '' && $kind !== 'voice') {
            $body['caption'] = $caption;
        }

        if (in_array($kind, ['voice', 'video'], true)) {
            $body['convert'] = true;
        }

        return $this->call('POST', $path, $body, 25);
    }

    /**
     * Sessions known to WAHA, each with its name, status and (when paired) the account in `me`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws WahaException
     */
    public function sessions(): array
    {
        return array_values((array) $this->call('GET', '/api/sessions', ['all' => 'true']));
    }

    /**
     * @param  'start'|'stop'|'restart'|'logout'  $action
     *
     * @throws WahaException
     */
    public function controlSession(string $session, string $action): void
    {
        $this->call('POST', '/api/sessions/'.rawurlencode($session).'/'.$action);
    }

    /**
     * The pairing QR as {mimetype, data} where data is base64. Only available while the
     * session waits for a scan (SCAN_QR_CODE).
     *
     * @return array{mimetype: string, data: string}
     *
     * @throws WahaException
     */
    public function qrCode(string $session): array
    {
        $json = (array) $this->call('GET', '/api/'.rawurlencode($session).'/auth/qr');
        $data = $json['data'] ?? null;

        if (! is_string($data) || $data === '') {
            throw new WahaException('WAHA did not return a QR code for this session.');
        }

        return ['mimetype' => (string) ($json['mimetype'] ?? 'image/png'), 'data' => $data];
    }

    /**
     * Pairing code to type in WhatsApp (Linked devices > Link with phone number).
     *
     * @throws WahaException
     */
    public function requestPairingCode(string $session, string $phone): string
    {
        $json = (array) $this->call('POST', '/api/'.rawurlencode($session).'/auth/request-code', ['phoneNumber' => $phone]);
        $code = $json['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new WahaException('WAHA did not return a pairing code.');
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data  Query string for GET, JSON body otherwise.
     * @return array<mixed>
     *
     * @throws WahaException
     */
    private function call(string $method, string $path, array $data = [], ?int $timeout = null): array
    {
        if (blank($this->baseUrl) || blank($this->apiKey)) {
            throw new WahaException('WAHA_API_URL and WAHA_API_KEY must be configured.');
        }

        try {
            $request = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders(['X-Api-Key' => $this->apiKey])
                ->acceptJson()
                ->timeout($timeout ?? $this->timeout);

            $response = $method === 'GET' ? $request->get($path, $data) : $request->post($path, $data);
        } catch (ConnectionException $exception) {
            throw new WahaException('The WAHA server did not answer in time.', null, true);
        }

        if ($response->failed()) {
            throw new WahaException(self::describe($response), $response->status());
        }

        return (array) $response->json();
    }

    /**
     * The id of the message WAHA just sent, in the same "bare" form the webhooks use
     * (the part after the last underscore). The exact response shape depends on the
     * engine, so a few known locations are tried.
     *
     * @param  array<string, mixed>  $response
     */
    public static function messageId(array $response): ?string
    {
        $candidates = [
            $response['id'] ?? null,
            $response['_data']['Info']['ID'] ?? null,
            $response['key']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['_serialized'] ?? $candidate['id'] ?? null;
            }

            if (is_string($candidate) && $candidate !== '') {
                $position = strrpos($candidate, '_');

                return $position === false ? $candidate : substr($candidate, $position + 1);
            }
        }

        return null;
    }

    private static function describe(Response $response): string
    {
        $json = $response->json();
        $detail = is_array($json) ? ($json['exception']['message'] ?? $json['message'] ?? null) : null;

        if (is_array($detail)) {
            $detail = implode(', ', $detail);
        }

        return 'WAHA answered '.$response->status().($detail ? ': '.$detail : '.');
    }
}
