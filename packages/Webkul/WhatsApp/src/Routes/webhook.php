<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\ReceiveMessageController;

Route::post('messages/receive', ReceiveMessageController::class)
    ->name('admin.whatsapp.messages.receive');
