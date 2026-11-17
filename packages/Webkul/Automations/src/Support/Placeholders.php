<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Lead\Models\Lead;

/**
 * {{lead}}, {{nome}}, {{etapa}}, {{funil}}, {{responsavel}} and {{link}} in a free text.
 */
final class Placeholders
{
    public const KEYS = ['lead', 'nome', 'etapa', 'funil', 'responsavel', 'link'];

    public static function render(string $text, Lead $lead): string
    {
        $values = self::values($lead);

        $rendered = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function (array $match) use ($values) {
            $key = strtolower($match[1]);

            return array_key_exists($key, $values) ? $values[$key] : $match[0];
        }, $text);

        return Str::limit((string) $rendered, 1500, '…');
    }

    /**
     * @return array<string, string>
     */
    public static function values(Lead $lead): array
    {
        return [
            'lead' => (string) $lead->title,
            'nome' => (string) ($lead->person_id ? DB::table('persons')->where('id', $lead->person_id)->value('name') : ''),
            'etapa' => (string) DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('name'),
            'funil' => (string) DB::table('lead_pipelines')->where('id', $lead->lead_pipeline_id)->value('name'),
            'responsavel' => (string) ($lead->user_id ? DB::table('users')->where('id', $lead->user_id)->value('name') : ''),
            'link' => self::link($lead),
        ];
    }

    /** Always on APP_URL: the job runs without a request. */
    public static function link(Lead $lead): string
    {
        return rtrim((string) config('app.url'), '/').route('admin.leads.view', ['id' => $lead->id], false);
    }
}
