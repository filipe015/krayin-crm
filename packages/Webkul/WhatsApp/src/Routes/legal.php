<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\LegalPageController;

// Public legal pages (no login). Registered with the "web" group only.
Route::get('privacidade', [LegalPageController::class, 'privacy'])->name('legal.privacy');

Route::get('termos', [LegalPageController::class, 'terms'])->name('legal.terms');

Route::get('exclusao-de-dados', [LegalPageController::class, 'deletion'])->name('legal.deletion');

Route::post('exclusao-de-dados', [LegalPageController::class, 'requestDeletion'])
    ->middleware('throttle:5,1')
    ->name('legal.deletion.request');

Route::get('exclusao-de-dados/status/{code}', [LegalPageController::class, 'deletionStatus'])
    ->where('code', '[A-Za-z0-9]{6,64}')
    ->name('legal.deletion.status');
