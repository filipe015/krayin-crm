<?php

namespace Webkul\WhatsApp\Channels\Cloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Server side of Meta's Embedded Signup: the browser gets a short-lived code from the
 * Facebook login popup and this class trades it for the customer's business token.
 * Neither the code, the app secret nor the token is ever logged.
 */
final class EmbeddedSignup
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $version = 'v23.0',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            app(Factory::class),
            (string) config('whatsapp.cloud.app_id'),
            (string) config('whatsapp.cloud.app_secret'),
            (string) config('whatsapp.cloud.graph_version', 'v23.0'),
        );
    }

    /**
     * What the "Connect with WhatsApp" button needs: the app, its secret and the signup configuration.
     */
    public static function configured(): bool
    {
        return filled(config('whatsapp.cloud.app_id'))
            && filled(config('whatsapp.cloud.app_secret'))
            && filled(config('whatsapp.cloud.es_config_id'));
    }

    /**
     * The code is valid for about 30 seconds and only once.
     *
     * @throws CloudApiException
     */
    public function exchangeCode(string $code): string
    {
        try {
            $response = $this->http->acceptJson()->timeout(15)->get(
                'https://graph.facebook.com/'.$this->version.'/oauth/access_token',
                ['client_id' => $this->appId, 'client_secret' => $this->appSecret, 'code' => $code],
            );
        } catch (ConnectionException) {
            throw new CloudApiException('The WhatsApp server did not answer in time.', null, true);
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            $error = $response->json('error');

            throw new CloudApiException(
                is_array($error) ? (string) ($error['message'] ?? 'Unknown error') : 'Meta answered '.$response->status().'.',
                $response->status(),
                false,
                is_array($error) && isset($error['code']) ? (int) $error['code'] : null,
            );
        }

        return $token;
    }
}
