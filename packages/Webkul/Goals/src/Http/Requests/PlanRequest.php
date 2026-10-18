<?php

namespace Webkul\Goals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Goals\Support\GoalPlanner;

/**
 * The numbers of a plan (to preview it or to save it): whole numbers only, rates from 1 to 100.
 */
class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $whole = ['nullable', 'integer', 'min:0', 'max:2000000000'];

        $rules = [
            'month'  => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'funnel' => ['nullable', 'string', 'max:120'],
            'fixed'  => ['nullable', 'array'],
            'fixed.*' => ['in:'.implode(',', GoalPlanner::STEPS)],
            'ticket' => ['nullable', 'integer', 'min:1', 'max:2000000000'],
        ];

        foreach (['revenue_target', 'cpl_target', 'budget', ...array_map(fn ($step) => $step.'_target', GoalPlanner::STEPS)] as $field) {
            $rules[$field] = $whole;
        }

        foreach (GoalPlanner::RATE_AFTER as $rate) {
            $rules[$rate] = ['nullable', 'integer', 'min:1', 'max:100'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return trans('goals::app.validation.attributes');
    }
}
