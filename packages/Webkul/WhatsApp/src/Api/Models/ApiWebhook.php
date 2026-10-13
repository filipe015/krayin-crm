<?php

namespace Webkul\WhatsApp\Api\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A URL the CRM calls when something happens (see WebhookEvents). The signing secret is stored
 * encrypted and shown once, when the webhook is created.
 */
class ApiWebhook extends Model
{
    protected $table = 'api_webhooks';

    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'last_status', 'last_error', 'last_sent_at', 'failures'];

    protected $casts = [
        'events'       => 'array',
        'is_active'    => 'boolean',
        'secret'       => 'encrypted',
        'last_sent_at' => 'datetime',
    ];

    protected $hidden = ['secret'];

    public function listensTo(string $event): bool
    {
        $events = (array) $this->events;

        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
