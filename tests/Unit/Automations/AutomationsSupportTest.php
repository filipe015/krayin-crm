<?php

use Illuminate\Database\Eloquent\Model;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Support\ActionRunner;
use Webkul\Automations\Support\AutomationContext;
use Webkul\Automations\Support\RuleValidator;
use Webkul\Automations\Support\TriggerMatcher;

/*
 * The pure parts of the automations: loop context, trigger matching, URL and WhatsApp destination checks.
 */
beforeEach(fn () => AutomationContext::reset());

it('stacks the automation context and restores it after a nested run', function () {
    expect(AutomationContext::active())->toBeFalse()->and(AutomationContext::depth())->toBe(0);

    AutomationContext::run(1, ['1:10'], 'A', function () {
        expect(AutomationContext::depth())->toBe(1)->and(AutomationContext::chain())->toBe(['1:10']);

        AutomationContext::run(2, ['1:10', '2:10'], 'B', function () {
            expect(AutomationContext::depth())->toBe(2)->and(AutomationContext::ruleName())->toBe('B');
        });

        expect(AutomationContext::depth())->toBe(1)->and(AutomationContext::ruleName())->toBe('A');
    });

    expect(AutomationContext::active())->toBeFalse();
});

it('restores the context even when the run throws', function () {
    try {
        AutomationContext::run(1, ['1:1'], 'A', fn () => throw new RuntimeException('x'));
    } catch (RuntimeException) {
    }

    expect(AutomationContext::active())->toBeFalse();
});

it('matches stage, tag, activity and new lead triggers', function () {
    // No application here: a dispatcher left by an earlier feature test would try to reach it.
    Model::unsetEventDispatcher();

    $rule = fn (string $type, array $config) => new AutomationRule(['trigger_type' => $type, 'trigger_config' => $config]);

    $stage = $rule('stage_entered', ['pipeline_id' => 1, 'stage_id' => 5, 'from_stage_id' => null]);
    $fromStage = $rule('stage_entered', ['pipeline_id' => 1, 'stage_id' => 5, 'from_stage_id' => 4]);

    expect(TriggerMatcher::matches($stage, 'stage_entered', ['stage_id' => 5, 'from_stage_id' => 2]))->toBeTrue()
        ->and(TriggerMatcher::matches($stage, 'stage_entered', ['stage_id' => 6]))->toBeFalse()
        ->and(TriggerMatcher::matches($stage, 'tag_added', ['stage_id' => 5]))->toBeFalse()
        ->and(TriggerMatcher::matches($fromStage, 'stage_entered', ['stage_id' => 5, 'from_stage_id' => 4]))->toBeTrue()
        ->and(TriggerMatcher::matches($fromStage, 'stage_entered', ['stage_id' => 5, 'from_stage_id' => 3]))->toBeFalse()
        ->and(TriggerMatcher::matches($rule('tag_added', ['tag_id' => 7]), 'tag_added', ['tag_id' => 7]))->toBeTrue()
        ->and(TriggerMatcher::matches($rule('tag_added', ['tag_id' => null]), 'tag_added', ['tag_id' => 7]))->toBeFalse()
        ->and(TriggerMatcher::matches($rule('activity_created', ['activity_type' => 'meeting']), 'activity_created', ['activity_type' => 'call']))->toBeFalse()
        ->and(TriggerMatcher::matches($rule('activity_created', ['activity_type' => null]), 'activity_created', ['activity_type' => 'call']))->toBeTrue()
        ->and(TriggerMatcher::matches($rule('lead_created', ['source_id' => 3, 'type_id' => null]), 'lead_created', ['source_id' => 3, 'type_id' => 9]))->toBeTrue()
        ->and(TriggerMatcher::matches($rule('lead_created', ['source_id' => 3]), 'lead_created', ['source_id' => null]))->toBeFalse();
});

it('accepts only public http(s) webhook URLs', function () {
    expect(RuleValidator::safeUrl('https://n8n.example.com/webhook/abc'))->toBeTrue()
        ->and(RuleValidator::safeUrl('http://8.8.8.8/x'))->toBeTrue()
        ->and(RuleValidator::safeUrl('http://localhost:8080/x'))->toBeFalse()
        ->and(RuleValidator::safeUrl('http://127.0.0.1/x'))->toBeFalse()
        ->and(RuleValidator::safeUrl('http://172.16.0.2/x'))->toBeFalse()
        ->and(RuleValidator::safeUrl('http://169.254.169.254/latest'))->toBeFalse()
        ->and(RuleValidator::safeUrl('http://[::1]/x'))->toBeFalse()
        ->and(RuleValidator::safeUrl('file:///etc/passwd'))->toBeFalse()
        ->and(RuleValidator::safeUrl('https://a:b@example.com'))->toBeFalse();
});

it('turns numbers and groups into WhatsApp chat ids', function () {
    expect(ActionRunner::chatIds('11 98888-7777, +55 (21) 97777-6666 , 120363@g.us, 123'))
        ->toBe(['5511988887777@c.us', '5521977776666@c.us', '120363@g.us']);
});
