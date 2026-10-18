<?php

use Illuminate\Support\Facades\Route;
use Webkul\Goals\Http\Controllers\GoalTrackingController;

Route::get('/', [GoalTrackingController::class, 'index'])->name('admin.goals.index');
Route::get('data', [GoalTrackingController::class, 'data'])->name('admin.goals.data');
