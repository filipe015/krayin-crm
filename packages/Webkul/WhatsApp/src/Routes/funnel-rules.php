<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\FunnelRuleController;

Route::get('/', [FunnelRuleController::class, 'index'])->name('admin.funnel-rules.index');
Route::post('/', [FunnelRuleController::class, 'store'])->name('admin.funnel-rules.store');
Route::put('{id}', [FunnelRuleController::class, 'update'])->whereNumber('id')->name('admin.funnel-rules.update');
Route::delete('{id}', [FunnelRuleController::class, 'destroy'])->whereNumber('id')->name('admin.funnel-rules.delete');
