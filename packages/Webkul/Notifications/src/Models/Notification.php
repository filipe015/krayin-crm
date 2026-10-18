<?php

namespace Webkul\Notifications\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'crm_notifications';

    protected $fillable = ['kind', 'level', 'title', 'body', 'url', 'user_id', 'dedupe_key', 'data'];

    protected $casts = ['data' => 'array'];

    /**
     * What a person sees: what is for everyone plus what is for them.
     */
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('user_id')->orWhere('user_id', $userId));
    }

    public function scopeUnreadBy(Builder $query, int $userId): Builder
    {
        return $query->whereNotExists(fn ($read) => $read->from('crm_notification_reads')
            ->whereColumn('crm_notification_reads.notification_id', 'crm_notifications.id')
            ->where('crm_notification_reads.user_id', $userId));
    }
}
