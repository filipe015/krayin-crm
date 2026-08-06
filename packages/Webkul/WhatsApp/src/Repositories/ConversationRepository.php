<?php

namespace Webkul\WhatsApp\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\WhatsApp\Contracts\Conversation;

class ConversationRepository extends Repository
{
    public function model(): string
    {
        return Conversation::class;
    }
}
