<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\ButtonRuleController;
use Webkul\WhatsApp\Http\Controllers\CloudTemplateController;
use Webkul\WhatsApp\Http\Controllers\ConnectionController;
use Webkul\WhatsApp\Http\Controllers\InboxController;
use Webkul\WhatsApp\Http\Controllers\InboxLeadController;
use Webkul\WhatsApp\Http\Controllers\InboxMessageController;
use Webkul\WhatsApp\Http\Controllers\LeadFunnelController;
use Webkul\WhatsApp\Http\Controllers\MessageMediaController;
use Webkul\WhatsApp\Http\Controllers\OpenLeadConversationController;
use Webkul\WhatsApp\Http\Controllers\QuickReplyController;

Route::get('/', [InboxController::class, 'index'])->name('admin.whatsapp.inbox.index');

Route::get('conversations', [InboxController::class, 'conversations'])->name('admin.whatsapp.inbox.conversations');
Route::get('conversations/{id}/messages', [InboxController::class, 'messages'])->name('admin.whatsapp.inbox.messages');
Route::post('conversations/{id}/read', [InboxController::class, 'markRead'])->name('admin.whatsapp.inbox.read');
Route::post('conversations/{id}/claim', [InboxController::class, 'claim'])->name('admin.whatsapp.inbox.claim');
Route::post('conversations/{id}/status', [InboxController::class, 'status'])->name('admin.whatsapp.inbox.status');
Route::post('conversations/{id}/lead', [InboxLeadController::class, 'link'])->name('admin.whatsapp.inbox.lead.link');
Route::post('conversations/{id}/lead/create', [InboxLeadController::class, 'create'])->name('admin.whatsapp.inbox.lead.create');
Route::post('conversations/{id}/messages', [InboxMessageController::class, 'store'])->name('admin.whatsapp.inbox.send');
Route::post('conversations/{id}/typing', [InboxMessageController::class, 'typing'])->name('admin.whatsapp.inbox.typing');
Route::post('messages/{id}/retry', [InboxMessageController::class, 'retry'])->name('admin.whatsapp.inbox.retry');

Route::get('leads', [InboxLeadController::class, 'search'])->name('admin.whatsapp.inbox.lead.search');
Route::get('messages/{id}/media', MessageMediaController::class)->name('admin.whatsapp.inbox.messages.media');

Route::get('connections', [ConnectionController::class, 'index'])->name('admin.whatsapp.connections.index');
Route::get('connections/list', [ConnectionController::class, 'list'])->name('admin.whatsapp.connections.list');
Route::get('connections/{id}/qr', [ConnectionController::class, 'qr'])->name('admin.whatsapp.connections.qr');
Route::post('connections/cloud', [ConnectionController::class, 'connectCloud'])->name('admin.whatsapp.connections.cloud.connect');
Route::post('connections/{id}/cloud/refresh', [ConnectionController::class, 'refreshCloud'])->name('admin.whatsapp.connections.cloud.refresh');
Route::post('connections/{id}/cloud/disconnect', [ConnectionController::class, 'disconnectCloud'])->name('admin.whatsapp.connections.cloud.disconnect');
Route::post('connections/{id}/code', [ConnectionController::class, 'code'])->name('admin.whatsapp.connections.code');
Route::post('connections/{id}/{action}', [ConnectionController::class, 'action'])->name('admin.whatsapp.connections.action');

Route::get('quick-replies', [QuickReplyController::class, 'index'])->name('admin.whatsapp.quick-replies.index');
Route::get('quick-replies/list', [QuickReplyController::class, 'list'])->name('admin.whatsapp.quick-replies.list');
Route::post('quick-replies', [QuickReplyController::class, 'store'])->name('admin.whatsapp.quick-replies.store');
Route::put('quick-replies/{id}', [QuickReplyController::class, 'update'])->name('admin.whatsapp.quick-replies.update');
Route::delete('quick-replies/{id}', [QuickReplyController::class, 'destroy'])->name('admin.whatsapp.quick-replies.delete');

Route::get('conversations/{id}/funnel', [LeadFunnelController::class, 'show'])->name('admin.whatsapp.inbox.funnel.show');
Route::post('conversations/{id}/funnel/stage', [LeadFunnelController::class, 'stage'])->name('admin.whatsapp.inbox.funnel.stage');
Route::post('conversations/{id}/funnel/tags', [LeadFunnelController::class, 'attachTag'])->name('admin.whatsapp.inbox.funnel.tags.attach');
Route::delete('conversations/{id}/funnel/tags/{tagId}', [LeadFunnelController::class, 'detachTag'])->name('admin.whatsapp.inbox.funnel.tags.detach');

Route::get('open-lead/{leadId}', OpenLeadConversationController::class)->name('admin.whatsapp.inbox.open-lead');

Route::put('messages/{id}/schedule', [InboxMessageController::class, 'reschedule'])->name('admin.whatsapp.inbox.schedule.update');
Route::delete('messages/{id}/schedule', [InboxMessageController::class, 'cancelSchedule'])->name('admin.whatsapp.inbox.schedule.cancel');

Route::get('conversations/{id}/templates', [CloudTemplateController::class, 'index'])->name('admin.whatsapp.inbox.templates.list');
Route::post('conversations/{id}/template', [CloudTemplateController::class, 'send'])->name('admin.whatsapp.inbox.templates.send');

Route::get('button-rules', [ButtonRuleController::class, 'list'])->name('admin.whatsapp.button-rules.list');
Route::post('button-rules', [ButtonRuleController::class, 'store'])->name('admin.whatsapp.button-rules.store');
Route::delete('button-rules/{id}', [ButtonRuleController::class, 'destroy'])->name('admin.whatsapp.button-rules.delete');
