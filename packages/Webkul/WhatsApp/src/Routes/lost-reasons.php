<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\LostReasonController;

Route::get('/', [LostReasonController::class, 'index'])->name('admin.lost-reasons.index');
Route::post('/', [LostReasonController::class, 'store'])->name('admin.lost-reasons.store');
Route::put('{id}', [LostReasonController::class, 'update'])->whereNumber('id')->name('admin.lost-reasons.update');
Route::delete('{id}', [LostReasonController::class, 'destroy'])->whereNumber('id')->name('admin.lost-reasons.delete');
