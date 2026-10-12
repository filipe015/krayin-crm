<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Carbon;

/**
 * Reads a "2026-10-15T09:00" value from the Inbox in the CRM's timezone and keeps it
 * between a minute from now and a year ahead.
 */
final class ScheduleTime
{
    public static function parse(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = Carbon::createFromFormat('Y-m-d\TH:i', $value, config('app.timezone'));

        abort_if($time->lt(now()->addMinute()), 422, trans('whatsapp::app.inbox.schedule_in_past'));
        abort_if($time->gt(now()->addYear()), 422, trans('whatsapp::app.inbox.schedule_too_far'));

        return $time;
    }
}
