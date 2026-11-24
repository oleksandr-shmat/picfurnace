<?php

declare(strict_types=1);

use App\Admin\ProfitReport;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;
use Carbon\CarbonImmutable;

mutates(ProfitReport::class);

function succeededAt(string $completedAt, int $providerCost, int $charged, ?User $user = null): Generation
{
    return Generation::factory()->succeeded($providerCost, $charged)->create([
        'user_id' => ($user ?? User::factory()->create())->id,
        'completed_at' => $completedAt,
    ]);
}

it('adds up what was charged and what it cost, per UTC day, newest day first', function () {
    succeededAt('2026-11-23 10:00:00', 1_000, 1_250);
    succeededAt('2026-11-23 18:30:00', 4_000, 5_000);
    succeededAt('2026-11-24 01:00:00', 2_000, 2_500);

    $days = (new ProfitReport)->perDay();

    expect($days)->toHaveCount(2)
        ->and($days[0]->key)->toBe('2026-11-24')
        ->and($days[0]->generations)->toBe(1)
        ->and($days[0]->charged->equals(Usd::nano(2_500)))->toBeTrue()
        ->and($days[0]->providerCost->equals(Usd::nano(2_000)))->toBeTrue()
        ->and($days[0]->profit()->equals(Usd::nano(500)))->toBeTrue()
        ->and($days[1]->key)->toBe('2026-11-23')
        ->and($days[1]->generations)->toBe(2)
        ->and($days[1]->charged->equals(Usd::nano(6_250)))->toBeTrue()
        ->and($days[1]->providerCost->equals(Usd::nano(5_000)))->toBeTrue()
        ->and($days[1]->profit()->equals(Usd::nano(1_250)))->toBeTrue();
});

it('puts a generation on the day it completed, not the day it was created', function () {
    Generation::factory()->succeeded(1_000, 1_250)->create([
        'created_at' => '2026-11-23 23:59:00',
        'completed_at' => '2026-11-24 00:00:30',
    ]);

    expect((new ProfitReport)->perDay()[0]->key)->toBe('2026-11-24');
});

it('splits days exactly at midnight UTC', function () {
    succeededAt('2026-11-23 23:59:59', 1_000, 1_250);
    succeededAt('2026-11-24 00:00:00', 1_000, 1_250);

    expect(array_map(fn ($day) => $day->key, (new ProfitReport)->perDay()))->toBe(['2026-11-24', '2026-11-23']);
});

it('counts only succeeded generations', function () {
    succeededAt('2026-11-24 10:00:00', 1_000, 1_250);
    Generation::factory()->failed()->create(['completed_at' => '2026-11-24 10:00:00']);
    Generation::factory()->create();

    $report = new ProfitReport;

    expect($report->perDay())->toHaveCount(1)
        ->and($report->perDay()[0]->generations)->toBe(1)
        ->and($report->total()->generations)->toBe(1);
});

it('adds up per user, the biggest spender first, and keeps users who left', function () {
    $small = User::factory()->create(['name' => 'Small Spender', 'email' => 'small@example.com']);
    $big = User::factory()->create(['name' => 'Big Spender', 'email' => 'big@example.com']);
    $gone = User::factory()->create(['name' => 'Gone', 'email' => 'gone@example.com']);
    succeededAt('2026-11-24 10:00:00', 1_000, 1_250, $small);
    succeededAt('2026-11-24 11:00:00', 4_000, 5_000, $big);
    succeededAt('2026-11-23 11:00:00', 4_000, 5_000, $big);
    succeededAt('2026-11-24 12:00:00', 2_000, 2_500, $gone);
    $gone->delete();

    $users = (new ProfitReport)->perUser();

    expect(array_map(fn ($row) => $row->label, $users))
        ->toBe(['Big Spender (big@example.com)', 'Gone (gone@example.com)', 'Small Spender (small@example.com)'])
        ->and($users[0]->generations)->toBe(2)
        ->and($users[0]->charged->equals(Usd::nano(10_000)))->toBeTrue()
        ->and($users[0]->providerCost->equals(Usd::nano(8_000)))->toBeTrue()
        ->and($users[0]->profit()->equals(Usd::nano(2_000)))->toBeTrue();
});

it('totals everything', function () {
    succeededAt('2026-11-23 10:00:00', 1_000, 1_250);
    succeededAt('2026-11-24 10:00:00', 4_000, 5_000);

    $total = (new ProfitReport)->total();

    expect($total->generations)->toBe(2)
        ->and($total->charged->equals(Usd::nano(6_250)))->toBeTrue()
        ->and($total->providerCost->equals(Usd::nano(5_000)))->toBeTrue()
        ->and($total->profit()->equals(Usd::nano(1_250)))->toBeTrue();
});

it('is empty and zero without any generation', function () {
    $report = new ProfitReport;

    expect($report->perDay())->toBe([])
        ->and($report->perUser())->toBe([])
        ->and($report->total()->generations)->toBe(0)
        ->and($report->total()->charged->isZero())->toBeTrue()
        ->and($report->total()->profit()->isZero())->toBeTrue();
});

it('shows only the latest days when there are many', function () {
    foreach (range(1, 5) as $day) {
        succeededAt("2026-11-0{$day} 10:00:00", 1_000, 1_250);
    }

    $days = (new ProfitReport)->perDay(limit: 3);

    expect(array_map(fn ($row) => $row->key, $days))->toBe(['2026-11-05', '2026-11-04', '2026-11-03']);
});

it('shows the latest 90 days by default', function () {
    foreach (range(0, 90) as $daysAgo) {
        succeededAt(CarbonImmutable::parse('2026-11-24')->subDays($daysAgo)->setTime(10, 0)->toDateTimeString(), 1_000, 1_250);
    }

    $days = (new ProfitReport)->perDay();

    expect($days)->toHaveCount(90)
        ->and($days[0]->key)->toBe('2026-11-24')
        ->and($days[89]->key)->toBe('2026-08-27');
});

it('says the ledger agrees when its charges equal the charges on the generations', function () {
    $first = succeededAt('2026-11-23 10:00:00', 1_000, 1_250);
    $second = succeededAt('2026-11-24 10:00:00', 4_000, 5_000);
    LedgerEntry::factory()->charge($first, 1_250)->create();
    LedgerEntry::factory()->charge($second, 5_000)->create();

    expect((new ProfitReport)->ledgerAgrees())->toBeTrue();
});

it('says the ledger disagrees when a charge is missing or different', function (int $ledgerCharge) {
    $generation = succeededAt('2026-11-24 10:00:00', 1_000, 1_250);
    if ($ledgerCharge > 0) {
        LedgerEntry::factory()->charge($generation, $ledgerCharge)->create();
    }

    expect((new ProfitReport)->ledgerAgrees())->toBeFalse();
})->with([
    'no charge in the ledger' => [0],
    'charge too small' => [1_249],
    'charge too big' => [1_251],
]);

it('agrees trivially without any charge', function () {
    expect((new ProfitReport)->ledgerAgrees())->toBeTrue();
});
