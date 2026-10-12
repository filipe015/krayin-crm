<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\AgendaSettingController;

Route::get('/', [AgendaSettingController::class, 'index'])->name('admin.agenda.settings.index');
Route::put('/', [AgendaSettingController::class, 'update'])->name('admin.agenda.settings.update');
