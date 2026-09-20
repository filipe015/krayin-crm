<?php

namespace Webkul\WhatsApp\Channels\Waha;

use RuntimeException;

final class WahaException extends RuntimeException
{
    /**
     * @param  bool  $outcomeUnknown  True when the request may still have reached WhatsApp
     *                                (timeout, dropped connection), so it must not be treated as a failure.
     */
    public function __construct(string $message, public readonly ?int $status = null, public readonly bool $outcomeUnknown = false)
    {
        parent::__construct($message);
    }
}
