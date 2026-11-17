<?php

namespace Webkul\Automations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Support\FlowConverter;

/**
 * Turns every old rule ("Quando… Se… Então…") into its straight-line flow, in place: same row, same
 * id (so the history and the "once per lead" memory stay with it), same name, status (Ligada,
 * Desligada, Teste) and "once per lead". The old columns are not touched.
 *
 * Idempotent: rules that already have a flow are skipped, so it may run any number of times.
 *
 * --revert puts the converted rules back in the old format (flow = NULL) when their old columns
 * still describe them (straight-line flows); flows with branches are listed and left alone.
 */
class ConvertRulesToFlows extends Command
{
    protected $signature = 'automations:convert-rules
        {--dry-run : Only list what would change}
        {--revert : Back to the old format (flow = NULL) for flows that the old columns still describe}';

    protected $description = 'Convert the old automation rules into visual-builder flows (idempotent)';

    public function handle(): int
    {
        if (! Schema::hasTable('automation_rules') || ! Schema::hasColumn('automation_rules', 'flow')) {
            $this->error('Run the migrations first (automation_rules.flow does not exist).');

            return self::FAILURE;
        }

        return $this->option('revert') ? $this->revert() : $this->convert();
    }

    private function convert(): int
    {
        $converted = 0;
        $skipped = 0;

        AutomationRule::query()->orderBy('id')->each(function (AutomationRule $rule) use (&$converted, &$skipped) {
            if ($rule->isFlow()) {
                $skipped++;

                return;
            }

            $this->line(sprintf('#%d %s (%s): %d condição(ões), %d ação(ões)', $rule->id, $rule->name, $rule->status, count((array) $rule->conditions), count((array) $rule->actions)));

            if (! $this->option('dry-run')) {
                // Only the flow column: status, name, "once per lead" and the old columns stay as they are.
                $rule->forceFill(['flow' => FlowConverter::fromRule($rule)])->saveQuietly();
            }

            $converted++;
        });

        $this->info(sprintf('%s: %d convertida(s), %d já eram fluxo.', $this->option('dry-run') ? 'Simulação' : 'Pronto', $converted, $skipped));

        return self::SUCCESS;
    }

    private function revert(): int
    {
        $reverted = 0;
        $kept = 0;

        AutomationRule::query()->whereNotNull('flow')->orderBy('id')->each(function (AutomationRule $rule) use (&$reverted, &$kept) {
            if (! $rule->isFlow()) {
                return;
            }

            $legacy = FlowConverter::toLegacy((array) $rule->flow);

            if ($legacy['actions'] === []) {
                $this->warn(sprintf('#%d %s: fluxo com ramificações, não cabe no formato antigo (mantido).', $rule->id, $rule->name));
                $kept++;

                return;
            }

            $this->line(sprintf('#%d %s: volta ao formato antigo.', $rule->id, $rule->name));

            if (! $this->option('dry-run')) {
                $rule->forceFill($legacy + ['flow' => null])->saveQuietly();
            }

            $reverted++;
        });

        $this->info(sprintf('%s: %d revertida(s), %d mantida(s) como fluxo.', $this->option('dry-run') ? 'Simulação' : 'Pronto', $reverted, $kept));

        return self::SUCCESS;
    }
}
