<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Activity reminder window
    |--------------------------------------------------------------------------
    |
    | Task activities whose deadline falls within this many hours are eligible
    | for a reminder. Reminders are checked by the scheduler every 15 minutes.
    |
    */
    'hours' => (int) env('ACTIVITY_REMINDER_HOURS', 24),
];
