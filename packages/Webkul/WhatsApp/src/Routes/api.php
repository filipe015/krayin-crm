<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Api\Http\Controllers\AdsApiController;
use Webkul\WhatsApp\Api\Http\Controllers\AppointmentApiController;
use Webkul\WhatsApp\Api\Http\Controllers\CatalogController;
use Webkul\WhatsApp\Api\Http\Controllers\LeadApiController;
use Webkul\WhatsApp\Api\Http\Controllers\ReportApiController;

// REST API v1 (prefix /api/v1). Authenticated by API key; see Configurações → Integração (API).
Route::get('ping', [CatalogController::class, 'ping']);
Route::get('pipelines', [CatalogController::class, 'pipelines']);
Route::get('users', [CatalogController::class, 'users']);
Route::get('sources', [CatalogController::class, 'sources']);
Route::get('tags', [CatalogController::class, 'tags']);

Route::get('leads', [LeadApiController::class, 'index']);
Route::post('leads', [LeadApiController::class, 'store']);
Route::get('leads/{id}', [LeadApiController::class, 'show'])->whereNumber('id');
Route::patch('leads/{id}/stage', [LeadApiController::class, 'stage'])->whereNumber('id');
Route::post('leads/{id}/notes', [LeadApiController::class, 'note'])->whereNumber('id');
Route::post('leads/{id}/tags', [LeadApiController::class, 'tags'])->whereNumber('id');
Route::post('leads/{id}/appointments', [AppointmentApiController::class, 'store'])->whereNumber('id');

Route::get('appointments', [AppointmentApiController::class, 'index']);
Route::post('appointments/{id}/done', [AppointmentApiController::class, 'done'])->whereNumber('id');

Route::get('reports/sales-cycle', [ReportApiController::class, 'salesCycle']);
Route::get('reports/leads-summary', [ReportApiController::class, 'leadsSummary']);

Route::post('ads/insights', [AdsApiController::class, 'ingest']);
Route::get('ads/performance', [AdsApiController::class, 'performance']);
