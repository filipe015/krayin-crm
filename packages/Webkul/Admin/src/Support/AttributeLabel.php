<?php

namespace Webkul\Admin\Support;

/**
 * The name of a custom/system field as people read it: the installer's translation when there is one
 * ("Valor Estimado do Lead"), else the name stored in the database. One place, so the form label, the
 * messages of the browser's validator and the server's validation messages all say the same thing.
 */
class AttributeLabel
{
    public static function for(object $attribute): string
    {
        $key = 'installer::app.seeders.attributes.'.$attribute->entity_type.'.'.str_replace('_', '-', $attribute->code);

        $label = trans($key);

        return $label === $key ? (string) $attribute->name : $label;
    }
}
