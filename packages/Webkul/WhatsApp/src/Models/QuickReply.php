<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A canned message the operators insert into the composer by typing "/shortcut".
 */
class QuickReply extends Model
{
    protected $table = 'whatsapp_quick_replies';

    protected $fillable = ['shortcut', 'title', 'body', 'created_by_user_id'];
}
