@props([
    'customAttributes' => [],
    'entity'           => null,
    'canAddNew'        => true,
])

@foreach ($customAttributes as $attribute)
    @php
        $validations = [];

        if ($attribute->is_required) {
            $validations[] = 'required';
        }

        if ($attribute->type == 'price') {
            $validations[] = 'decimal';
        }

        $validations[] = $attribute->validation;

        $validations = implode('|', array_filter($validations));

        $label = \Webkul\Admin\Support\AttributeLabel::for($attribute);

        // The edit components use the attribute's name as the field label in validation messages.
        $translated = clone $attribute;
        $translated->name = $label;
    @endphp

    <x-admin::form.control-group class="mb-2.5 w-full">
        <x-admin::form.control-group.label
            for="{{ $attribute->code }}"
            :class="$attribute->is_required ? 'required' : ''"
        >
            {{ $label }}

        </x-admin::form.control-group.label>

        @if (isset($attribute))
            <x-admin::attributes.edit.index
                :attribute="$translated"
                :validations="$validations"
                :value="isset($entity) ? $entity[$attribute->code] : null"
                :can-add-new="$canAddNew"
            />
        @endif

        <x-admin::form.control-group.error :control-name="$attribute->code" />
    </x-admin::form.control-group>
@endforeach