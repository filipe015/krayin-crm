<?php

namespace Webkul\WhatsApp\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\WhatsApp\Contracts\Message;

class MessageRepository extends Repository
{
    public function model(): string
    {
        return Message::class;
    }
}
