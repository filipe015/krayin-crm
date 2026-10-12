<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "When a customer taps this button, move the lead to a stage and/or tag it."
 */
class ButtonRule extends Model
{
    protected $table = 'whatsapp_button_rules';

    protected $fillable = ['match_text', 'stage_id', 'tag_id'];
}
