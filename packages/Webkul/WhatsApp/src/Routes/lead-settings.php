<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\LeadSettingController;

Route::get('/', [LeadSettingController::class, 'index'])->name('admin.whatsapp.lead-settings.index');
Route::put('/', [LeadSettingController::class, 'update'])->name('admin.whatsapp.lead-settings.update');
