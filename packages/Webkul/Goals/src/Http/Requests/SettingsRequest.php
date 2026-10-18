<?php

namespace Webkul\Goals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Goals\Support\GoalSettingsService;

/**
 * How the funnel steps are recognised and the default weekdays.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A rule only carries what its type uses (a tag rule that was a stage rule a moment ago still has an
     * empty list of stages in the form: that is not a mistake).
     */
    protected function prepareForValidation(): void
    {
        $keep = ['stage' => ['type', 'pipeline_id', 'stage_ids'], 'tag' => ['type', 'tag_ids'], 'appointment' => ['type']];

        $rules = (array) $this->input('step_rules', []);

        foreach ($rules as $step => $list) {
            $rules[$step] = array_values(array_map(
                fn ($rule) => is_array($rule) ? array_intersect_key($rule, array_flip($keep[$rule['type'] ?? ''] ?? ['type'])) : $rule,
                (array) $list,
            ));
        }

        $this->merge(['step_rules' => $rules]);
    }

    public function rules(): array
    {
        $rules = ['weekdays' => ['required', 'array', 'min:1'], 'weekdays.*' => ['integer', 'between:1,7']];

        foreach (GoalSettingsService::STEPS as $step) {
            $rules["step_rules.{$step}"] = ['nullable', 'array'];
            $rules["step_rules.{$step}.*.type"] = ['required', 'in:stage,tag,appointment'];
            $rules["step_rules.{$step}.*.pipeline_id"] = ['required_if:step_rules.'.$step.'.*.type,stage', 'nullable', 'integer', 'min:1'];
            $rules["step_rules.{$step}.*.stage_ids"] = ['required_if:step_rules.'.$step.'.*.type,stage', 'nullable', 'array', 'min:1'];
            $rules["step_rules.{$step}.*.tag_ids"] = ['required_if:step_rules.'.$step.'.*.type,tag', 'nullable', 'array', 'min:1'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'step_rules.*.*.stage_ids.required_if' => trans('goals::app.validation.rule_stages'),
            'step_rules.*.*.pipeline_id.required_if' => trans('goals::app.validation.rule_stages'),
            'step_rules.*.*.tag_ids.required_if'   => trans('goals::app.validation.rule_tags'),
        ];
    }
}
