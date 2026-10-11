<?php

namespace Webkul\WhatsApp\Channels\Cloud;

use RuntimeException;

final class CloudApiException extends RuntimeException
{
    /**
     * Graph error codes that mean "the 24-hour customer service window is closed".
     */
    public const WINDOW_CLOSED_CODES = [131047, 131051, 470];

    /**
     * @param  bool  $outcomeUnknown  True when the request may still have reached WhatsApp
     *                                (timeout, dropped connection), so it must not be treated as a failure.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $outcomeUnknown = false,
        public readonly ?int $graphCode = null,
    ) {
        parent::__construct($message);
    }

    public function isWindowClosed(): bool
    {
        return $this->graphCode !== null && in_array($this->graphCode, self::WINDOW_CLOSED_CODES, true);
    }
}
