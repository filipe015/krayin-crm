<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\AdsController;

Route::get('/', [AdsController::class, 'index'])->name('admin.ads.index');
Route::get('data', [AdsController::class, 'data'])->name('admin.ads.data');
