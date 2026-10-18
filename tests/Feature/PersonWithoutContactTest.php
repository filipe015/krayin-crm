<?php

use Webkul\Contact\Repositories\PersonRepository;

/**
 * How a contact's data is prepared before it is saved, when it comes from a name only
 * (the "Adicionar Negócio" card without e-mail or phone).
 */
function sanitizedPerson(array $data): array
{
    $method = new ReflectionMethod(PersonRepository::class, 'sanitizeRequestedPersonData');

    return $method->invoke(app(PersonRepository::class), $data);
}

it('ignores an empty phone box, which arrives as null', function () {
    $data = sanitizedPerson([
        'name'            => 'Sem telefone',
        'emails'          => [['value' => 'sem-telefone@example.test', 'label' => 'work']],
        'contact_numbers' => [['value' => null, 'label' => 'work']],
    ]);

    expect($data['contact_numbers'])->toBe([])
        ->and($data['unique_id'])->toBe('sem-telefone@example.test');
});

it('keys on the phone when there is one', function () {
    $data = sanitizedPerson([
        'name'            => 'Com telefone',
        'emails'          => [['value' => null, 'label' => 'work']],
        'contact_numbers' => [['value' => null, 'label' => 'work'], ['value' => '61981585036', 'label' => 'work']],
    ]);

    expect($data['contact_numbers'])->toHaveCount(1)
        ->and($data['unique_id'])->toContain('61981585036');
});

it('gives a person that has only a name a key of its own', function () {
    $empty = ['name' => 'Só nome', 'emails' => [['value' => null, 'label' => 'work']], 'contact_numbers' => [['value' => null, 'label' => 'work']]];

    expect(sanitizedPerson($empty)['unique_id'])->not->toBe(sanitizedPerson($empty)['unique_id']);
});
