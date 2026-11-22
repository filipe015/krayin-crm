<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Webkul\Activity\Models\Activity;
use Webkul\Attribute\Models\Attribute;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\Conversation;

/**
 * Where the value of a template variable ({{1}}, {{2}}, ...) comes from in the CRM.
 *
 * A source is a plain string: one of BASE, `custom:<lead attribute code>` or `text:<fixed text>`.
 * The same resolver feeds the Inbox ("Enviar modelo") and the appointment reminders.
 */
final class TemplateVariables
{
    public const BASE = [
        'contact_first_name', 'contact_name', 'owner_name', 'clinic_name',
        'appointment_date', 'appointment_time', 'appointment_datetime', 'appointment_location', 'lead_title',
    ];

    private const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

    public static function valid(string $source): bool
    {
        if (in_array($source, self::BASE, true)) {
            return true;
        }

        if (str_starts_with($source, 'custom:')) {
            return preg_match('/^[a-z0-9_]{1,100}$/', substr($source, 7)) === 1;
        }

        return str_starts_with($source, 'text:') && trim(substr($source, 5)) !== '' && mb_strlen($source) <= 205;
    }

    /**
     * The custom fields of a lead that can feed a variable.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function customFields(): array
    {
        return Attribute::query()
            ->where('entity_type', 'leads')
            ->where('is_user_defined', 1)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($attribute) => ['code' => (string) $attribute->code, 'name' => (string) $attribute->name])
            ->values()
            ->all();
    }

    /**
     * A readable name for a source, for the Inbox form.
     *
     * @param  array<string, string>  $customNames  attribute code => name
     */
    public static function label(string $source, array $customNames = []): string
    {
        if (str_starts_with($source, 'text:')) {
            return trans('whatsapp::app.templates.source_text').' '.mb_substr(substr($source, 5), 0, 30);
        }

        if (str_starts_with($source, 'custom:')) {
            return $customNames[substr($source, 7)] ?? substr($source, 7);
        }

        return (string) trans('whatsapp::app.connections.reminder_sources.'.$source);
    }

    /**
     * The next appointment of the lead (the one a "{{date}}" variable should talk about).
     */
    public static function nextAppointment(Lead $lead, string $type = 'meeting'): ?Activity
    {
        return Activity::query()
            ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->where('lead_activities.lead_id', $lead->id)
            ->where('activities.type', $type)
            ->where('activities.is_done', 0)
            ->where('activities.schedule_from', '>', now())
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(activities.additional, '$.kind')), '') <> 'followup'")
            ->orderBy('activities.schedule_from')
            ->first(['activities.*']);
    }

    /**
     * The text a variable receives. Never empty, because the Cloud API refuses empty parameters.
     */
    public static function resolve(string $source, ?Lead $lead, ?Activity $activity, ?Conversation $conversation, ?User $fallbackUser = null): string
    {
        $when = $activity?->schedule_from?->copy()->setTimezone(config('app.timezone'));
        $name = trim((string) ($lead?->person?->name ?: $conversation?->contact_name ?: ''));

        if (str_starts_with($source, 'text:')) {
            return trim(substr($source, 5)) ?: '-';
        }

        if (str_starts_with($source, 'custom:')) {
            $value = $lead === null ? '' : self::custom($lead, substr($source, 7));

            return $value !== '' ? $value : '-';
        }

        $value = match ($source) {
            'contact_first_name'   => Str::before($name, ' '),
            'contact_name'         => $name,
            'owner_name'           => (string) ($lead?->user?->name ?: $activity?->user?->name ?: $fallbackUser?->name ?: ''),
            'clinic_name'          => (string) config('app.name'),
            'appointment_date'     => $when?->format('d/m') ?? '',
            'appointment_time'     => $when !== null ? self::time($when) : '',
            'appointment_datetime' => $when !== null ? self::WEEKDAYS[$when->dayOfWeek].', '.$when->format('d/m').' às '.self::time($when) : '',
            'appointment_location' => (string) ($activity->location ?? ''),
            'lead_title'           => (string) ($lead?->title ?? ''),
            default                => '',
        };

        $value = trim($value);

        return $value !== '' ? $value : match ($source) {
            'contact_first_name', 'contact_name' => 'cliente',
            'owner_name'                         => 'nossa equipe',
            default                              => '-',
        };
    }

    private static function custom(Lead $lead, string $code): string
    {
        $attribute = Attribute::query()->where('entity_type', 'leads')->where('code', $code)->first();

        if ($attribute === null) {
            return '';
        }

        $value = $lead->getAttribute($code);

        if (in_array($attribute->type, ['select', 'lookup'], true) && is_numeric($value)) {
            return trim((string) ($attribute->options()->whereKey((int) $value)->value('name') ?? ''));
        }

        if (is_array($value)) {
            $value = collect($value)->map(fn ($item) => is_array($item) ? ($item['value'] ?? '') : $item)->filter()->implode(', ');
        }

        if ($value instanceof Carbon) {
            $value = $value->format($attribute->type === 'datetime' ? 'd/m/Y H:i' : 'd/m/Y');
        }

        return trim((string) $value);
    }

    private static function time(Carbon $when): string
    {
        return $when->minute === 0 ? $when->format('G').'h' : $when->format('G\hi');
    }
}
