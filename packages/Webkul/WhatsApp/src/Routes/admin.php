<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\LeadMessageController;

Route::get('{leadId}/whatsapp/messages', [LeadMessageController::class, 'index'])
    ->name('admin.leads.whatsapp.messages.index');
