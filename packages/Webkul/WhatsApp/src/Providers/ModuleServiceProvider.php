<?php

namespace Webkul\WhatsApp\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        Conversation::class,
        Message::class,
        Session::class,
    ];
}
