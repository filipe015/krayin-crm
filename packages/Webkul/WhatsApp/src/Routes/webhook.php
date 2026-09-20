<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\LookupLeadController;
use Webkul\WhatsApp\Http\Controllers\ReceiveMessageController;
use Webkul\WhatsApp\Http\Controllers\WahaWebhookController;

Route::get('leads/lookup', LookupLeadController::class)
    ->name('admin.whatsapp.leads.lookup');

Route::post('messages/receive', ReceiveMessageController::class)
    ->name('admin.whatsapp.messages.receive');

Route::post('waha/webhook', WahaWebhookController::class)
    ->name('admin.whatsapp.waha.webhook');
