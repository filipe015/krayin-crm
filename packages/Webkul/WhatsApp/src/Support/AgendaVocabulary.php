<?php

namespace Webkul\WhatsApp\Support;

use Webkul\WhatsApp\Models\AgendaSetting;

/**
 * Turns the Agenda texts into this installation's words ("Nova consulta" or "Nova call de
 * demonstração"). Strings carry :appointment / :Appointment / :appointments / :responsible
 * placeholders; the ones that depend on gender are written once for each gender.
 */
final class AgendaVocabulary
{
    /**
     * @return array<string, string>
     */
    public static function labels(AgendaSetting $setting): array
    {
        $gender = $setting->gender === 'f' ? 'f' : 'm';

        // Longer names first: ":appointment" would otherwise eat ":appointments".
        $replace = [
            'appointments' => mb_strtolower($setting->plural),
            'appointment'  => mb_strtolower($setting->singular),
            'responsibles' => mb_strtolower($setting->responsible_plural),
            'responsible'  => mb_strtolower($setting->responsible),
        ];

        $labels = [];

        foreach ((array) trans('whatsapp::app.agenda') as $key => $value) {
            if (is_array($value)) {
                $value = $value[$gender] ?? reset($value);
            }

            $labels[$key] = self::replace((string) $value, $replace);
        }

        return $labels;
    }

    /**
     * :word keeps the case, :Word capitalizes the first letter, :WORD upper-cases it.
     *
     * @param  array<string, string>  $replace
     */
    private static function replace(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(
                [':'.$key, ':'.ucfirst($key), ':'.strtoupper($key)],
                [$value, mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1), mb_strtoupper($value)],
                $line,
            );
        }

        return $line;
    }
}
