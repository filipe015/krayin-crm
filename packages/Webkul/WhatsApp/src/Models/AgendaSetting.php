<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How this installation calls what it books ("Consulta", "Call de demonstração"...), who it is
 * booked with, and how the person in charge is picked when nobody chooses one.
 */
class AgendaSetting extends Model
{
    public const MODES = ['manual', 'round_robin', 'least_busy'];

    public const DEFAULTS = [
        'singular'           => 'Agendamento',
        'plural'             => 'Agendamentos',
        'gender'             => 'm',
        'responsible'        => 'Responsável',
        'responsible_plural' => 'Responsáveis',
        'default_duration'   => 30,
        'day_start'          => 7,
        'day_end'            => 21,
        'distribution_mode'  => 'manual',
    ];

    protected $table = 'whatsapp_agenda_settings';

    protected $fillable = [
        'singular', 'plural', 'gender', 'responsible', 'responsible_plural', 'default_duration',
        'day_start', 'day_end', 'distribution_mode', 'distribution_user_ids', 'last_assigned_user_id', 'stage_triggers',
    ];

    protected $casts = ['distribution_user_ids' => 'array', 'stage_triggers' => 'array'];

    /**
     * The saved settings, or the neutral defaults while nothing was saved.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new static(self::DEFAULTS);
    }

    /**
     * What opens the booking card when a deal reaches a stage: stage id => kind ("appointment" or
     * "followup"). Empty until the installation chooses its stages.
     *
     * @return array<int, string>
     */
    public static function stageTriggers(): array
    {
        $triggers = [];

        foreach ((array) (static::current()->stage_triggers ?? []) as $trigger) {
            if (isset($trigger['stage_id'], $trigger['kind'])) {
                $triggers[(int) $trigger['stage_id']] = $trigger['kind'];
            }
        }

        return $triggers;
    }
}
