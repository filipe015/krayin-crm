<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Notifications\Models\Notification;
use Webkul\Notifications\Support\Notifier;

uses(DatabaseTransactions::class);

/**
 * The notification center (the bell): the service anyone uses to notify, and what a person sees.
 * Needs a migrated and seeded database.
 */
it('sends a notification, and never the same one twice', function () {
    $first = Notifier::send('Meta atrasada', 'Faltam 3 vendas', Notifier::WARNING, '/admin/goals', null, 'teste:dia-1', 'goal');
    $second = Notifier::send('Meta atrasada', 'Faltam 3 vendas', Notifier::WARNING, '/admin/goals', null, 'teste:dia-1', 'goal');
    $next = Notifier::send('Meta atrasada', '', Notifier::WARNING, null, null, 'teste:dia-2');

    expect($first)->not->toBeNull()
        ->and($first->level)->toBe('warning')
        ->and($first->kind)->toBe('goal')
        ->and($second)->toBeNull()
        ->and($next)->not->toBeNull()
        ->and(Notification::query()->where('dedupe_key', 'like', 'teste:%')->count())->toBe(2);
});

it('shows each person what is for everyone and what is for them', function () {
    $admin = getDefaultAdmin();

    $everyone = Notifier::send('Para todos');
    $mine = Notifier::send('Só para mim', '', Notifier::INFO, null, $admin->id);
    $other = Notifier::send('De outra pessoa', '', Notifier::INFO, null, $admin->id + 100000);

    $ids = Notification::query()->visibleTo($admin->id)->pluck('id');

    expect($ids)->toContain($everyone->id, $mine->id)
        ->and($ids)->not->toContain($other->id);
});

it('lists the latest notifications with the unread count, and marks them as read per person', function () {
    $admin = getDefaultAdmin();
    $one = Notifier::send('Um', 'texto', Notifier::DANGER);
    $two = Notifier::send('Dois');

    $list = test()->actingAs($admin)->getJson(route('admin.notifications.index'))->assertOk();

    expect($list->json('unread'))->toBeGreaterThanOrEqual(2)
        ->and($list->json('latest_id'))->toBeGreaterThanOrEqual($two->id)
        ->and(collect($list->json('items'))->firstWhere('id', $one->id)['read'])->toBeFalse();

    $before = $list->json('unread');

    test()->actingAs($admin)->postJson(route('admin.notifications.read'), ['id' => $one->id])->assertOk();

    $after = test()->actingAs($admin)->getJson(route('admin.notifications.index'));

    expect($after->json('unread'))->toBe($before - 1)
        ->and(collect($after->json('items'))->firstWhere('id', $one->id)['read'])->toBeTrue();

    test()->actingAs($admin)->postJson(route('admin.notifications.read_all'))->assertOk();

    expect(test()->actingAs($admin)->getJson(route('admin.notifications.index'))->json('unread'))->toBe(0);
});

it('does not let anyone mark as read what is not theirs', function () {
    $admin = getDefaultAdmin();
    $foreign = Notifier::send('De outra pessoa', '', Notifier::INFO, null, $admin->id + 100000);

    test()->actingAs($admin)->postJson(route('admin.notifications.read'), ['id' => $foreign->id])->assertOk();

    expect(DB::table('crm_notification_reads')->where('notification_id', $foreign->id)->count())->toBe(0);
});

it('deletes old notifications', function () {
    $old = Notifier::send('Antiga');
    $old->forceFill(['created_at' => now()->subDays(90)])->save();
    $recent = Notifier::send('Recente');

    $this->artisan('notifications:prune', ['--days' => 60])->assertSuccessful();

    expect(Notification::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Notification::query()->whereKey($recent->id)->exists())->toBeTrue();
});
