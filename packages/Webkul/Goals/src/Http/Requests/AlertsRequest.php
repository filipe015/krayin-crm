<?php

namespace Webkul\Goals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Goals\Support\GoalAlertSettings;

/**
 * The rules of the automatic alerts. Which metric a rule watches only matters for the kinds that have one.
 */
class AlertsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled'         => ['required', 'boolean'],
            'rules'           => ['nullable', 'array', 'max:50'],
            'rules.*.kind'    => ['required', 'in:'.implode(',', GoalAlertSettings::KINDS)],
            'rules.*.metric'  => ['nullable', 'string'],
            'rules.*.percent' => ['required', 'integer', 'between:1,500'],
        ];
    }
}
