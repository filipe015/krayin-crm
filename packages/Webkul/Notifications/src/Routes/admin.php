<?php

use Illuminate\Support\Facades\Route;
use Webkul\Notifications\Http\Controllers\NotificationController;

Route::get('/', [NotificationController::class, 'index'])->name('admin.notifications.index');
Route::post('read', [NotificationController::class, 'read'])->name('admin.notifications.read');
Route::post('read-all', [NotificationController::class, 'readAll'])->name('admin.notifications.read_all');
