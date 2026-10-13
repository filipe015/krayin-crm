<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\ApiSettingController;

Route::get('/', [ApiSettingController::class, 'index'])->name('admin.api.settings.index');
Route::get('list', [ApiSettingController::class, 'list'])->name('admin.api.settings.list');
Route::post('keys', [ApiSettingController::class, 'storeKey'])->name('admin.api.settings.keys.store');
Route::delete('keys/{id}', [ApiSettingController::class, 'revokeKey'])->name('admin.api.settings.keys.revoke');
Route::post('webhooks', [ApiSettingController::class, 'storeWebhook'])->name('admin.api.settings.webhooks.store');
Route::put('webhooks/{id}', [ApiSettingController::class, 'updateWebhook'])->name('admin.api.settings.webhooks.update');
Route::delete('webhooks/{id}', [ApiSettingController::class, 'destroyWebhook'])->name('admin.api.settings.webhooks.delete');
Route::post('webhooks/{id}/test', [ApiSettingController::class, 'testWebhook'])->name('admin.api.settings.webhooks.test');
