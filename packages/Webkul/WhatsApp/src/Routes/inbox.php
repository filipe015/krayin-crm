<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\InboxController;
use Webkul\WhatsApp\Http\Controllers\InboxLeadController;
use Webkul\WhatsApp\Http\Controllers\MessageMediaController;

Route::get('/', [InboxController::class, 'index'])->name('admin.whatsapp.inbox.index');

Route::get('conversations', [InboxController::class, 'conversations'])->name('admin.whatsapp.inbox.conversations');
Route::get('conversations/{id}/messages', [InboxController::class, 'messages'])->name('admin.whatsapp.inbox.messages');
Route::post('conversations/{id}/read', [InboxController::class, 'markRead'])->name('admin.whatsapp.inbox.read');
Route::post('conversations/{id}/claim', [InboxController::class, 'claim'])->name('admin.whatsapp.inbox.claim');
Route::post('conversations/{id}/status', [InboxController::class, 'status'])->name('admin.whatsapp.inbox.status');
Route::post('conversations/{id}/lead', [InboxLeadController::class, 'link'])->name('admin.whatsapp.inbox.lead.link');

Route::get('leads', [InboxLeadController::class, 'search'])->name('admin.whatsapp.inbox.lead.search');
Route::get('messages/{id}/media', MessageMediaController::class)->name('admin.whatsapp.inbox.messages.media');
