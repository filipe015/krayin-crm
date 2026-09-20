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
