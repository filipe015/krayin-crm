<?php

namespace Webkul\Automations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Automations\Support\Engine;

/**
 * Runs one rule on one lead for one event. The engine's idempotency key makes a second delivery of
 * the same job a no-op; a single attempt avoids a retry repeating actions that already went out
 * (a WhatsApp notice, a webhook).
 */
class RunAutomationRule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $chain
     */
    public function __construct(
        public int $ruleId,
        public int $leadId,
        public string $trigger,
        public array $context = [],
        public int $depth = 0,
        public array $chain = [],
    ) {}

    public function handle(Engine $engine): void
    {
        try {
            $engine->runFromEvent($this->ruleId, $this->leadId, $this->trigger, $this->context, $this->depth, $this->chain);
        } catch (Throwable $exception) {
            Log::warning('Automations: rule run crashed.', ['rule_id' => $this->ruleId, 'lead_id' => $this->leadId, 'error' => $exception->getMessage()]);
        }
    }
}
