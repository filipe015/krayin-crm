<?php

namespace Webkul\Automations\Support;

/**
 * Loop protection. While a rule runs its actions, the changes it makes to a lead fire the same events
 * a person's changes do (stage entered, tag added…). This context tells the listener that those
 * events come from an automation, how deep the chain already is and which rule x lead pairs are in it:
 *
 *  - an event made by a person has depth 0; the rule it fires runs at depth 1; an event made by that
 *    rule's actions fires rules at depth 2, and so on, up to automations.max_depth (3);
 *  - a rule never fires again on the same lead inside the same chain (A → B → A is cut at the 2nd A).
 *
 * The context is a stack, because with the sync queue (tests, local) a rule fired inside another
 * one runs right there, nested. With the database queue each job carries its depth and chain and
 * re-opens the context when it runs.
 */
final class AutomationContext
{
    /** @var list<array{depth: int, chain: list<string>, rule: string}> */
    private static array $stack = [];

    /**
     * @template T
     *
     * @param  list<string>  $chain  "ruleId:leadId" pairs already in the chain, this one included
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(int $depth, array $chain, string $ruleName, callable $callback): mixed
    {
        self::$stack[] = ['depth' => $depth, 'chain' => array_values(array_unique($chain)), 'rule' => $ruleName];

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    public static function active(): bool
    {
        return self::$stack !== [];
    }

    /** Depth of the automation running now (0 = no automation: a person, the API, a webhook…). */
    public static function depth(): int
    {
        return self::$stack === [] ? 0 : end(self::$stack)['depth'];
    }

    /** @return list<string> */
    public static function chain(): array
    {
        return self::$stack === [] ? [] : end(self::$stack)['chain'];
    }

    public static function ruleName(): ?string
    {
        return self::$stack === [] ? null : end(self::$stack)['rule'];
    }

    public static function key(int $ruleId, int $leadId): string
    {
        return $ruleId.':'.$leadId;
    }

    public static function reset(): void
    {
        self::$stack = [];
    }
}
