<?php

use Illuminate\Support\Facades\Route;
use Webkul\Goals\Http\Controllers\GoalSettingsController;

Route::get('/', [GoalSettingsController::class, 'index'])->name('admin.goals.settings.index');
Route::get('data', [GoalSettingsController::class, 'data'])->name('admin.goals.settings.data');
Route::get('history', [GoalSettingsController::class, 'history'])->name('admin.goals.settings.history');

// Editing
Route::post('preview', [GoalSettingsController::class, 'preview'])->name('admin.goals.settings.preview');
Route::post('plan', [GoalSettingsController::class, 'savePlan'])->name('admin.goals.settings.plan');
Route::delete('plan', [GoalSettingsController::class, 'deletePlan'])->name('admin.goals.settings.plan.delete');
Route::post('copy', [GoalSettingsController::class, 'copyMonth'])->name('admin.goals.settings.copy');
Route::post('working-days', [GoalSettingsController::class, 'saveWorkingDays'])->name('admin.goals.settings.working_days');
Route::post('settings', [GoalSettingsController::class, 'saveSettings'])->name('admin.goals.settings.save');
Route::post('alerts', [GoalSettingsController::class, 'saveAlerts'])->name('admin.goals.settings.alerts');
