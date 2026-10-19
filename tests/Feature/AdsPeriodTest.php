<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/**
 * Anúncios by any period (the range picker), not only by month.
 */
function adRow(string $date, float $spend, string $id): void
{
    DB::table('ad_insights_daily')->insert(['account_id' => 'period-test', 'date' => $date, 'ad_id' => $id, 'platform' => 'facebook', 'campaign_name' => 'Campanha teste', 'spend' => $spend, 'created_at' => now(), 'updated_at' => now()]);
}

it('sums only the days of the chosen period', function () {
    adRow('2031-05-03', 100, 'a1');
    adRow('2031-05-12', 250, 'a2');
    adRow('2031-05-28', 400, 'a3');

    $response = test()->actingAs(getDefaultAdmin())
        ->getJson(route('admin.ads.overview', ['start' => '2031-05-10', 'end' => '2031-05-20']))
        ->assertOk();

    expect($response->json('summary.spend'))->toEqual(250)
        ->and($response->json('start'))->toBe('2031-05-10')
        ->and($response->json('end'))->toBe('2031-05-20')
        ->and($response->json('label'))->toBe('10/05/2031 – 20/05/2031');
});

it('still answers for a whole month', function () {
    adRow('2031-06-03', 100, 'b1');
    adRow('2031-06-28', 400, 'b2');

    $response = test()->actingAs(getDefaultAdmin())->getJson(route('admin.ads.overview', ['month' => '2031-06']))->assertOk();

    expect($response->json('summary.spend'))->toEqual(500)->and($response->json('month'))->toBe('2031-06');
});

it('refuses a period that ends before it starts or runs past a year', function () {
    $admin = getDefaultAdmin();

    test()->actingAs($admin)->getJson(route('admin.ads.overview', ['start' => '2031-05-20', 'end' => '2031-05-10']))->assertStatus(422);
    test()->actingAs($admin)->getJson(route('admin.ads.overview', ['start' => '2029-01-01', 'end' => '2031-05-10']))->assertStatus(422);
});
