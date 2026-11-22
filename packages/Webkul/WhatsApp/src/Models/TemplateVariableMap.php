<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateVariableMap extends Model
{
    protected $table = 'whatsapp_template_variables';

    protected $fillable = ['template_name', 'template_language', 'sources'];

    protected $casts = ['sources' => 'array'];

    /**
     * The saved sources of a template, one per variable, or [] when it has none.
     *
     * @return list<string>
     */
    public static function sourcesFor(string $name, string $language): array
    {
        $row = static::query()->where('template_name', $name)->where('template_language', $language)->first();

        return $row === null ? [] : array_values((array) $row->sources);
    }
}
