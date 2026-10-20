<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;
use Webkul\WhatsApp\Contracts\Conversation as ConversationContract;

class Conversation extends Model implements ConversationContract
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'lead_id',
        'session_id',
        'chat_id',
        'canal_origem',
        'external_conversation_id',
        'contact_phone',
        'contact_lid',
        'contact_name',
        'assigned_user_id',
        'status',
        'last_message_at',
        'last_message_preview',
        'last_inbound_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionProxy::modelClass(), 'session_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass(), 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessageProxy::modelClass(), 'conversation_id');
    }

    /**
     * What the logged user may see, following the visibility set on the user (all, group or only their own):
     * the conversations still without a lead (the queue is shared), the ones assigned to the user and the
     * ones of leads the user can see. A global view (or no logged user: webhooks, API) sees everything.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeVisible(Builder $query): Builder
    {
        $pipelineIds = bouncer()->getAllowedPipelineIds();

        // Restricted to some pipelines: only the conversations of deals in them (plus the queue, when the user sees it).
        if ($pipelineIds !== null) {
            $query->where(function (Builder $inner) use ($pipelineIds) {
                $inner->whereIn('lead_id', DB::table('leads')->whereIn('lead_pipeline_id', $pipelineIds ?: [0])->select('id'));

                if (auth()->guard('user')->user()?->inbox_queue) {
                    $inner->orWhereNull('lead_id');
                }
            });
        }

        $userIds = bouncer()->getAuthorizedUserIds();

        if ($userIds === null) {
            return $query;
        }

        $me = (int) auth()->guard('user')->id();

        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('lead_id')
            ->orWhere('assigned_user_id', $me)
            ->orWhereIn('lead_id', DB::table('leads')->whereIn('user_id', $userIds)->select('id')));
    }
}
