<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Api\Http\Controllers\AdsApiController;
use Webkul\WhatsApp\Api\Http\Controllers\AppointmentApiController;
use Webkul\WhatsApp\Api\Http\Controllers\CatalogController;
use Webkul\WhatsApp\Api\Http\Controllers\DeleteApiController;
use Webkul\WhatsApp\Api\Http\Controllers\LeadApiController;
use Webkul\WhatsApp\Api\Http\Controllers\ReportApiController;

// REST API v1 (prefix /api/v1). Authenticated by API key; see Configurações → Integração (API).
Route::get('ping', [CatalogController::class, 'ping']);
Route::get('pipelines', [CatalogController::class, 'pipelines']);
Route::post('pipelines', [CatalogController::class, 'createPipeline']);
Route::get('attributes', [CatalogController::class, 'attributes']);
Route::post('attributes', [CatalogController::class, 'createAttribute']);
Route::get('users', [CatalogController::class, 'users']);
Route::get('sources', [CatalogController::class, 'sources']);
Route::post('sources', [CatalogController::class, 'createSource']);
Route::get('tags', [CatalogController::class, 'tags']);
Route::post('tags', [CatalogController::class, 'createTag']);

Route::get('leads', [LeadApiController::class, 'index']);
Route::post('leads', [LeadApiController::class, 'store']);
Route::get('leads/{id}', [LeadApiController::class, 'show'])->whereNumber('id');
Route::patch('leads/{id}', [LeadApiController::class, 'update'])->whereNumber('id');
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

// Deletions (key with the "delete" ability).
Route::delete('leads/{id}', [DeleteApiController::class, 'lead'])->whereNumber('id');
Route::get('imports/{system}', [DeleteApiController::class, 'importSummary'])->where('system', '[A-Za-z0-9_-]{1,40}');
Route::delete('imports/{system}', [DeleteApiController::class, 'purgeImport'])->where('system', '[A-Za-z0-9_-]{1,40}');
Route::delete('pipelines/{id}', [DeleteApiController::class, 'pipeline'])->whereNumber('id');
Route::delete('attributes/{code}', [DeleteApiController::class, 'attribute']);
Route::delete('tags/{id}', [DeleteApiController::class, 'tag'])->whereNumber('id');
Route::delete('sources/{id}', [DeleteApiController::class, 'source'])->whereNumber('id');
