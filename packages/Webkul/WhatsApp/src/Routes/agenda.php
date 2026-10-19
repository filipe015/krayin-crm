<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\AgendaController;

Route::get('/', [AgendaController::class, 'index'])->name('admin.agenda.index');
Route::get('events', [AgendaController::class, 'events'])->name('admin.agenda.events');
Route::get('leads', [AgendaController::class, 'leads'])->name('admin.agenda.leads');
Route::post('appointments', [AgendaController::class, 'store'])->name('admin.agenda.store');
Route::post('appointments/{id}/done', [AgendaController::class, 'done'])->name('admin.agenda.done');
Route::post('stage-prompt', [AgendaController::class, 'stagePrompt'])->name('admin.agenda.stage-prompt');
