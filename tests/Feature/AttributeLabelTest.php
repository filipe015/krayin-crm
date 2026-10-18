<?php

use Webkul\Admin\Support\AttributeLabel;

it('uses the installer translation of a system field, and the stored name otherwise', function () {
    app()->setLocale('pt_BR');

    $system = (object) ['entity_type' => 'leads', 'code' => 'lead_value', 'name' => 'Estimated Lead Value'];
    $custom = (object) ['entity_type' => 'leads', 'code' => 'zz_campo_proprio', 'name' => 'Modalidade de Atendimento'];

    expect(AttributeLabel::for($system))->toBe('Valor Estimado do Lead')
        ->and(AttributeLabel::for($custom))->toBe('Modalidade de Atendimento');
});

it('says the decimal rule message in the app language, with the readable field name', function () {
    app()->setLocale('pt_BR');

    $validator = validator(['lead_value' => 'abc'], ['lead_value' => [new \Webkul\Core\Contracts\Validations\Decimal]], [], ['lead_value' => 'Valor Estimado do Lead']);

    expect($validator->errors()->first('lead_value'))->toBe('O campo Valor Estimado do Lead deve ser um número válido.');
});
