<?php

/**
 * Automações (Configurações → Automação → Automações): "Quando… Se… Então…" rules on leads.
 *
 * The module is born OFF in every installation: nothing is listened to, nothing is queued and the
 * screen does not exist until AUTOMATIONS_ENABLED=true (and the migrations were run by hand).
 */
return [
    // Master switch. Off: no rule runs, no menu, the screen answers 404.
    'enabled' => (bool) env('AUTOMATIONS_ENABLED', false),

    // Hide the native "Workflows" entry (Configurações → Automação → Workflows) from the menu.
    // It only hides the entry: native workflows that already exist keep running.
    'hide_native' => (bool) env('AUTOMATIONS_HIDE_NATIVE', false),

    // How many automations may chain into each other (an automation's change firing another one).
    'max_depth' => (int) env('AUTOMATIONS_MAX_DEPTH', 3),

    // History (automation_runs) older than this many days is deleted by automations:purge (daily).
    'retention_days' => (int) env('AUTOMATIONS_RETENTION_DAYS', 90),

    // Most rules one CRM may have.
    'max_rules' => (int) env('AUTOMATIONS_MAX_RULES', 100),

    // Most blocks (gatilho + condições + ações) one flow of the visual builder may have.
    'max_nodes' => (int) env('AUTOMATIONS_MAX_NODES', 30),

    // Outgoing webhook action: short timeouts, a single attempt.
    'webhook_timeout' => (int) env('AUTOMATIONS_WEBHOOK_TIMEOUT', 5),

    // Queue that runs the automations (null = the default one).
    'queue' => env('AUTOMATIONS_QUEUE'),

    // Fase 2 (tempo) — bloco "Esperar" e gatilhos de tempo, avaliados por automations:tick (a cada minuto).

    // Longest pause a "Esperar" block may ask for.
    'max_wait_days' => (int) env('AUTOMATIONS_MAX_WAIT_DAYS', 30),

    // Most pending waits (paused flows) one CRM may hold; a new wait beyond it fails the run (history).
    'max_pending_waits' => (int) env('AUTOMATIONS_MAX_PENDING_WAITS', 10000),

    // How many due waits are queued per minute, and how many leads one time trigger fires per minute.
    'wait_batch' => (int) env('AUTOMATIONS_WAIT_BATCH', 200),
    'time_trigger_batch' => (int) env('AUTOMATIONS_TIME_TRIGGER_BATCH', 200),

    // Finished waits (resumed / cancelled / expired) older than this are deleted by automations:purge.
    'waits_retention_days' => (int) env('AUTOMATIONS_WAITS_RETENTION_DAYS', 30),
];
