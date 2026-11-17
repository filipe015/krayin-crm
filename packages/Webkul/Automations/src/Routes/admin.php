<?php

use Illuminate\Support\Facades\Route;
use Webkul\Automations\Http\Controllers\AutomationRuleController;

Route::get('/', [AutomationRuleController::class, 'index'])->name('admin.automations.index');
Route::get('runs', [AutomationRuleController::class, 'runs'])->name('admin.automations.runs');
Route::post('/', [AutomationRuleController::class, 'store'])->name('admin.automations.store');
Route::put('{id}', [AutomationRuleController::class, 'update'])->whereNumber('id')->name('admin.automations.update');
Route::patch('{id}/status', [AutomationRuleController::class, 'status'])->whereNumber('id')->name('admin.automations.status');
Route::post('{id}/simulate', [AutomationRuleController::class, 'simulate'])->whereNumber('id')->name('admin.automations.simulate');
Route::delete('{id}', [AutomationRuleController::class, 'destroy'])->whereNumber('id')->name('admin.automations.delete');
