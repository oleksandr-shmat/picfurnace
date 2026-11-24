<?php

declare(strict_types=1);

use App\Admin\Period;
use App\Admin\ProfitReport;
use App\Generations\GenerationLifecycle;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;
use Carbon\CarbonImmutable;

mutates(ProfitReport::class);

function succeededAt(string $completedAt, int $providerCost, int $charged, ?User $user = null, array $override = []): Generation
{
    return Generation::factory()->succeeded($providerCost, $charged)->create($override + [
        'user_id' => ($user ?? User::factory()->create())->id,
        'completed_at' => $completedAt,
    ]);
}

function november(): Period
{
    return Period::between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
}

function keysOf(iterable $rows): array
{
    return collect($rows)->map(fn ($row) => $row->key)->all();
}

function nano(int $amount): Money\Money
{
    return Usd::nano($amount);
}

describe('summary', function () {
    it('adds up the succeeded generations of the period', function () {
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250);
        succeededAt('2026-11-11 10:00:00', 4_000, 5_000);

        $summary = (new ProfitReport)->summary(november());

        expect($summary->generations)->toBe(2)
            ->and($summary->charged->equals(nano(6_250)))->toBeTrue()
            ->and($summary->providerCost->equals(nano(5_000)))->toBeTrue()
            ->and($summary->profit()->equals(nano(1_250)))->toBeTrue();
    });

    it('includes the first moment of the period and leaves out the first moment after it', function () {
        succeededAt('2026-10-31 23:59:59', 1_000, 1_000);
        succeededAt('2026-11-01 00:00:00', 1_000, 2_000);
        succeededAt('2026-11-30 23:59:59', 1_000, 4_000);
        succeededAt('2026-12-01 00:00:00', 1_000, 8_000);

        $summary = (new ProfitReport)->summary(november());

        expect($summary->generations)->toBe(2)
            ->and($summary->charged->equals(nano(6_000)))->toBeTrue();
    });

    it('takes everything for all time', function () {
        succeededAt('2020-01-01 00:00:00', 1_000, 1_250);
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250);

        expect((new ProfitReport)->summary(Period::all())->generations)->toBe(2);
    });

    it('counts a generation on the day it completed, not the day it was created', function () {
        Generation::factory()->succeeded(1_000, 1_250)->create([
            'created_at' => '2026-10-31 23:59:00',
            'completed_at' => '2026-11-01 00:00:30',
        ]);

        expect((new ProfitReport)->summary(november())->generations)->toBe(1);
    });

    it('counts failures, but not what the user cancelled, and not what is not finished', function () {
        Generation::factory()->failed()->create(['completed_at' => '2026-11-10 10:00:00', 'failure_code' => 'generation_failed']);
        Generation::factory()->failed()->create(['completed_at' => '2026-11-10 11:00:00', 'failure_code' => null]);
        Generation::factory()->failed()->create(['completed_at' => '2026-11-10 12:00:00', 'failure_code' => GenerationLifecycle::CANCELLED_BY_USER]);
        Generation::factory()->failed()->create(['completed_at' => '2026-10-10 12:00:00']);
        Generation::factory()->create();

        $summary = (new ProfitReport)->summary(november());

        expect($summary->failed)->toBe(2)
            ->and($summary->generations)->toBe(0);
    });

    it('is zero for a period without anything', function () {
        $summary = (new ProfitReport)->summary(november());

        expect($summary->generations)->toBe(0)
            ->and($summary->failed)->toBe(0)
            ->and($summary->charged->isZero())->toBeTrue()
            ->and($summary->providerCost->isZero())->toBeTrue();
    });

    it('can be limited to one user', function () {
        $ann = User::factory()->create();
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, $ann);
        succeededAt('2026-11-10 10:00:00', 4_000, 5_000);
        Generation::factory()->failed()->create(['user_id' => $ann->id, 'completed_at' => '2026-11-10 10:00:00']);
        Generation::factory()->failed()->create(['completed_at' => '2026-11-10 10:00:00']);

        $summary = (new ProfitReport)->summary(november(), $ann->id);

        expect($summary->generations)->toBe(1)
            ->and($summary->failed)->toBe(1)
            ->and($summary->charged->equals(nano(1_250)))->toBeTrue();
    });
});

describe('series', function () {
    it('has a point for every day up to today, also the quiet ones', function () {
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250);
        succeededAt('2026-11-12 10:00:00', 1_000, 2_500);
        $period = Period::between(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-13'));

        $series = (new ProfitReport)->series($period, null, CarbonImmutable::parse('2026-11-20'));

        expect(keysOf($series))->toBe(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'])
            ->and($series[0]->charged->equals(nano(1_250)))->toBeTrue()
            ->and($series[1]->charged->isZero())->toBeTrue()
            ->and($series[1]->providerCost->isZero())->toBeTrue()
            ->and($series[1]->generations)->toBe(0)
            ->and($series[2]->charged->equals(nano(2_500)))->toBeTrue();
    });

    it('stops at today when the period reaches into the future', function () {
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250);

        $series = (new ProfitReport)->series(november(), null, CarbonImmutable::parse('2026-11-12 15:00:00'));

        expect(array_slice(keysOf($series), -1))->toBe(['2026-11-12'])
            ->and($series)->toHaveCount(12);
    });

    it('shows the latest 90 days of a longer period', function () {
        succeededAt('2026-01-01 10:00:00', 1_000, 1_250);
        $period = Period::between(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-11-24'));

        $series = (new ProfitReport)->series($period, null, CarbonImmutable::parse('2026-11-24'));

        expect($series)->toHaveCount(90)
            ->and($series[0]->key)->toBe('2026-08-27')
            ->and($series[89]->key)->toBe('2026-11-24');
    });

    it('starts all time at the first generation', function () {
        succeededAt('2026-11-20 10:00:00', 1_000, 1_250);

        $series = (new ProfitReport)->series(Period::all(), null, CarbonImmutable::parse('2026-11-22'));

        expect(keysOf($series))->toBe(['2026-11-20', '2026-11-21', '2026-11-22']);
    });

    it('is empty for all time without a generation', function () {
        expect((new ProfitReport)->series(Period::all(), null, CarbonImmutable::parse('2026-11-22')))->toBe([]);
    });

    it('can be limited to one user', function () {
        $ann = User::factory()->create();
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, $ann);
        succeededAt('2026-11-10 10:00:00', 4_000, 5_000);
        $period = Period::between(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-10'));

        $series = (new ProfitReport)->series($period, $ann->id, CarbonImmutable::parse('2026-11-20'));

        expect($series[0]->charged->equals(nano(1_250)))->toBeTrue();
    });
});

describe('days', function () {
    it('adds up per UTC day and splits exactly at midnight', function () {
        succeededAt('2026-11-23 23:59:59', 1_000, 1_250);
        succeededAt('2026-11-24 00:00:00', 2_000, 2_500);
        succeededAt('2026-11-24 12:00:00', 4_000, 5_000);

        $days = (new ProfitReport)->days(november())->items();

        expect(keysOf($days))->toBe(['2026-11-24', '2026-11-23'])
            ->and($days[0]->generations)->toBe(2)
            ->and($days[0]->charged->equals(nano(7_500)))->toBeTrue()
            ->and($days[0]->providerCost->equals(nano(6_000)))->toBeTrue()
            ->and($days[0]->profit()->equals(nano(1_500)))->toBeTrue()
            ->and($days[1]->generations)->toBe(1);
    });

    it('only has the days of the period and only succeeded generations', function () {
        succeededAt('2026-10-31 10:00:00', 1_000, 1_250);
        succeededAt('2026-11-02 10:00:00', 1_000, 1_250);
        Generation::factory()->failed()->create(['completed_at' => '2026-11-03 10:00:00']);
        Generation::factory()->create();

        expect(keysOf((new ProfitReport)->days(november())->items()))->toBe(['2026-11-02']);
    });

    it('can be limited to one user', function () {
        $ann = User::factory()->create();
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, $ann);
        succeededAt('2026-11-11 10:00:00', 4_000, 5_000);

        expect(keysOf((new ProfitReport)->days(november(), $ann->id)->items()))->toBe(['2026-11-10']);
    });

    it('sorts by every column in both directions', function (string $sort, string $direction, array $expected) {
        // day 1: 1 generation, 1_000 charged, 800 cost, profit 200
        // day 2: 3 generations, 3_000 charged, 3_000 cost, profit 0
        // day 3: 2 generations, 6_000 charged, 2_000 cost, profit 4_000
        succeededAt('2026-11-01 10:00:00', 800, 1_000);
        foreach ([1_000, 1_000, 1_000] as $price) {
            succeededAt('2026-11-02 10:00:00', 1_000, $price);
        }
        foreach ([3_000, 3_000] as $price) {
            succeededAt('2026-11-03 10:00:00', 1_000, $price);
        }

        $days = (new ProfitReport)->days(november(), null, $sort, $direction);

        expect(keysOf($days->items()))->toBe($expected);
    })->with([
        'day newest first' => ['day', 'desc', ['2026-11-03', '2026-11-02', '2026-11-01']],
        'day oldest first' => ['day', 'asc', ['2026-11-01', '2026-11-02', '2026-11-03']],
        'most generations' => ['generations', 'desc', ['2026-11-02', '2026-11-03', '2026-11-01']],
        'fewest generations' => ['generations', 'asc', ['2026-11-01', '2026-11-03', '2026-11-02']],
        'most charged' => ['charged', 'desc', ['2026-11-03', '2026-11-02', '2026-11-01']],
        'least charged' => ['charged', 'asc', ['2026-11-01', '2026-11-02', '2026-11-03']],
        'highest cost' => ['provider_cost', 'desc', ['2026-11-02', '2026-11-03', '2026-11-01']],
        'most profit' => ['profit', 'desc', ['2026-11-03', '2026-11-01', '2026-11-02']],
        'least profit' => ['profit', 'asc', ['2026-11-02', '2026-11-01', '2026-11-03']],
    ]);

    it('falls back to the newest day first for a sort it does not know', function () {
        succeededAt('2026-11-01 10:00:00', 1_000, 1_250);
        succeededAt('2026-11-02 10:00:00', 1_000, 1_250);

        $days = (new ProfitReport)->days(november(), null, 'name; drop table users', 'sideways');

        expect(keysOf($days->items()))->toBe(['2026-11-02', '2026-11-01']);
    });

    it('pages the days and says how many there are', function () {
        foreach (range(1, 5) as $day) {
            succeededAt("2026-11-0{$day} 10:00:00", 1_000, 1_250);
        }

        $second = (new ProfitReport)->days(november(), null, 'day', 'desc', perPage: 2, page: 2);

        expect(keysOf($second->items()))->toBe(['2026-11-03', '2026-11-02'])
            ->and($second->total())->toBe(5)
            ->and($second->currentPage())->toBe(2)
            ->and($second->lastPage())->toBe(3)
            ->and($second->perPage())->toBe(2);
    });

    it('shows fifteen days on a page unless told otherwise', function () {
        foreach (range(1, 20) as $day) {
            succeededAt('2026-11-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00:00', 1_000, 1_250);
        }

        $days = (new ProfitReport)->days(november());

        expect($days->items())->toHaveCount(15)
            ->and($days->perPage())->toBe(15)
            ->and($days->currentPage())->toBe(1);
    });

    it('has every day when not paged, in the sort asked for', function () {
        foreach (range(1, 20) as $day) {
            succeededAt('2026-11-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00:00', 1_000, 1_250);
        }

        $days = (new ProfitReport)->allDays(november(), null, 'day', 'asc');

        expect($days)->toHaveCount(20)
            ->and($days[0]->key)->toBe('2026-11-01')
            ->and($days[19]->key)->toBe('2026-11-20');
    });

    it('has no days for a period without generations', function () {
        $days = (new ProfitReport)->days(november());

        expect($days->items())->toBe([])
            ->and($days->total())->toBe(0);
    });
});

describe('users', function () {
    it('adds up per user, biggest spender first, and keeps users who left', function () {
        $small = User::factory()->create(['name' => 'Small Spender', 'email' => 'small@example.com']);
        $big = User::factory()->create(['name' => 'Big Spender', 'email' => 'big@example.com']);
        $gone = User::factory()->create(['name' => 'Gone', 'email' => 'gone@example.com']);
        succeededAt('2026-11-24 10:00:00', 1_000, 1_250, $small);
        succeededAt('2026-11-24 11:00:00', 4_000, 5_000, $big);
        succeededAt('2026-11-23 11:00:00', 4_000, 5_000, $big);
        succeededAt('2026-11-24 12:00:00', 2_000, 2_500, $gone);
        $gone->delete();

        $users = (new ProfitReport)->users(november())->items();

        expect(array_map(fn ($row) => $row->label, $users))
            ->toBe(['Big Spender (big@example.com)', 'Gone (gone@example.com)', 'Small Spender (small@example.com)'])
            ->and($users[0]->key)->toBe((string) $big->id)
            ->and($users[0]->generations)->toBe(2)
            ->and($users[0]->charged->equals(nano(10_000)))->toBeTrue()
            ->and($users[0]->providerCost->equals(nano(8_000)))->toBeTrue()
            ->and($users[0]->profit()->equals(nano(2_000)))->toBeTrue();
    });

    it('only has users with a succeeded generation in the period', function () {
        $active = User::factory()->create(['name' => 'Active']);
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, $active);
        succeededAt('2026-10-10 10:00:00', 1_000, 1_250);
        User::factory()->create();

        expect((new ProfitReport)->users(november())->total())->toBe(1);
    });

    it('shows when the user last had something finished and what they have in credit', function () {
        $ann = User::factory()->create();
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, $ann);
        succeededAt('2026-11-12 08:30:00', 1_000, 1_250, $ann);
        LedgerEntry::factory()->create(['user_id' => $ann->id, 'amount_nano' => 5_000]);

        $row = (new ProfitReport)->users(november())->items()[0];

        expect($row->lastActivity->toDateTimeString())->toBe('2026-11-12 08:30:00')
            ->and($row->balance->equals(nano(5_000)))->toBeTrue();
    });

    it('shows a zero credit for a user with no ledger lines', function () {
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250);

        expect((new ProfitReport)->users(november())->items()[0]->balance->isZero())->toBeTrue();
    });

    it('can be limited to one user', function () {
        $ann = User::factory()->create();
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, $ann);
        succeededAt('2026-11-10 10:00:00', 4_000, 5_000);

        expect((new ProfitReport)->users(november(), $ann->id)->total())->toBe(1);
    });

    it('searches by name or e-mail, ignoring case, and takes % and _ literally', function (string $search, array $expectedNames) {
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, User::factory()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']));
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, User::factory()->create(['name' => 'Bob 100%', 'email' => 'bob_smith@corp.test']));
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, User::factory()->create(['name' => 'Cy', 'email' => 'cy@example.com']));

        $found = (new ProfitReport)->users(november(), null, $search, 'user', 'asc')->items();

        expect(array_map(fn ($row) => explode(' (', $row->label)[0], $found))->toBe($expectedNames);
    })->with([
        'part of a name' => ['LEE', ['Ann Lee']],
        'part of an e-mail' => ['corp.test', ['Bob 100%']],
        'a domain of two' => ['@example', ['Ann Lee', 'Cy']],
        'a percent sign is not a wildcard' => ['%', ['Bob 100%']],
        'an underscore is not a wildcard' => ['_', ['Bob 100%']],
        'nobody' => ['zzz', []],
        'nothing means everybody' => ['', ['Ann Lee', 'Bob 100%', 'Cy']],
    ]);

    it('sorts by every column in both directions', function (string $sort, string $direction, array $expected) {
        $ann = User::factory()->create(['name' => 'Ann']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $cy = User::factory()->create(['name' => 'Cy']);
        // Ann: 1 generation, 1_000 charged, 800 cost, last 2026-11-05, credit 300
        // Bob: 3 generations, 3_000 charged, 3_000 cost, last 2026-11-02, credit 100
        // Cy: 2 generations, 6_000 charged, 2_000 cost, last 2026-11-09, credit 200
        succeededAt('2026-11-05 10:00:00', 800, 1_000, $ann);
        foreach ([1, 1, 2] as $day) {
            succeededAt("2026-11-0{$day} 10:00:00", 1_000, 1_000, $bob);
        }
        succeededAt('2026-11-08 10:00:00', 1_000, 3_000, $cy);
        succeededAt('2026-11-09 10:00:00', 1_000, 3_000, $cy);
        foreach ([[$ann, 300], [$bob, 100], [$cy, 200]] as [$user, $credit]) {
            LedgerEntry::factory()->create(['user_id' => $user->id, 'amount_nano' => $credit]);
        }

        $users = (new ProfitReport)->users(november(), null, null, $sort, $direction);

        expect(array_map(fn ($row) => explode(' (', $row->label)[0], $users->items()))->toBe($expected);
    })->with([
        'name a to z' => ['user', 'asc', ['Ann', 'Bob', 'Cy']],
        'name z to a' => ['user', 'desc', ['Cy', 'Bob', 'Ann']],
        'most generations' => ['generations', 'desc', ['Bob', 'Cy', 'Ann']],
        'most charged' => ['charged', 'desc', ['Cy', 'Bob', 'Ann']],
        'highest cost' => ['provider_cost', 'desc', ['Bob', 'Cy', 'Ann']],
        'most profit' => ['profit', 'desc', ['Cy', 'Ann', 'Bob']],
        'least profit' => ['profit', 'asc', ['Bob', 'Ann', 'Cy']],
        'latest activity' => ['last_activity', 'desc', ['Cy', 'Ann', 'Bob']],
        'most credit' => ['balance', 'desc', ['Ann', 'Cy', 'Bob']],
    ]);

    it('shows fifteen users on a page unless told otherwise', function () {
        foreach (range(1, 17) as $i) {
            succeededAt('2026-11-10 10:00:00', 1_000, $i * 1_000, User::factory()->create());
        }

        $users = (new ProfitReport)->users(november());

        expect($users->items())->toHaveCount(15)
            ->and($users->perPage())->toBe(15)
            ->and($users->currentPage())->toBe(1)
            ->and($users->total())->toBe(17);
    });

    it('has every user when not paged, with the search and the sort asked for', function () {
        foreach (range(1, 17) as $i) {
            succeededAt('2026-11-10 10:00:00', 1_000, $i * 1_000, User::factory()->create(['name' => "Ann {$i}", 'email' => "ann{$i}@example.com"]));
        }
        // fixed e-mail: a random one could contain "ann"
        succeededAt('2026-11-10 10:00:00', 1_000, 1_000, User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']));

        $everybody = (new ProfitReport)->allUsers(november());
        $anns = (new ProfitReport)->allUsers(november(), null, 'ann', 'charged', 'asc');

        expect($everybody)->toHaveCount(18)
            ->and($anns)->toHaveCount(17)
            ->and($anns[0]->label)->toStartWith('Ann 1 (')
            ->and($anns[16]->label)->toStartWith('Ann 17 (');
    });

    it('falls back to the biggest spender first for a sort it does not know', function () {
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, User::factory()->create(['name' => 'Small']));
        succeededAt('2026-11-10 10:00:00', 1_000, 9_000, User::factory()->create(['name' => 'Big']));

        $users = (new ProfitReport)->users(november(), null, null, 'password', 'sideways')->items();

        expect($users[0]->label)->toStartWith('Big');
    });

    it('pages the users', function () {
        foreach (range(1, 5) as $i) {
            succeededAt('2026-11-10 10:00:00', 1_000, $i * 1_000, User::factory()->create(['name' => "User {$i}"]));
        }

        $page = (new ProfitReport)->users(november(), null, null, 'charged', 'desc', perPage: 2, page: 3);

        expect($page->items())->toHaveCount(1)
            ->and($page->items()[0]->label)->toStartWith('User 1')
            ->and($page->total())->toBe(5)
            ->and($page->lastPage())->toBe(3);
    });
});

describe('breakdown', function () {
    it('shows what is bought: generations and revenue per quality, the biggest first', function () {
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, null, ['quality' => 'low']);
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, null, ['quality' => 'low']);
        succeededAt('2026-11-10 10:00:00', 4_000, 5_000, null, ['quality' => 'high']);
        succeededAt('2026-10-10 10:00:00', 4_000, 5_000, null, ['quality' => 'medium']);

        $rows = (new ProfitReport)->breakdown(november(), null, 'quality');

        expect(keysOf($rows))->toBe(['high', 'low'])
            ->and($rows[0]->generations)->toBe(1)
            ->and($rows[0]->charged->equals(nano(5_000)))->toBeTrue()
            ->and($rows[1]->generations)->toBe(2)
            ->and($rows[1]->charged->equals(nano(2_500)))->toBeTrue();
    });

    it('does the same per size and can be limited to one user', function () {
        $ann = User::factory()->create();
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, $ann, ['size' => '1024x1536']);
        succeededAt('2026-11-10 10:00:00', 1_000, 1_250, null, ['size' => '1536x1024']);

        expect(keysOf((new ProfitReport)->breakdown(november(), $ann->id, 'size')))->toBe(['1024x1536']);
    });

    it('refuses a column it does not know', function () {
        (new ProfitReport)->breakdown(november(), null, 'prompt');
    })->throws(InvalidArgumentException::class);
});

describe('credit', function () {
    it('adds up the credit bought in the period, not what was granted or spent', function () {
        $ann = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $ann->id]);
        LedgerEntry::factory()->create(['user_id' => $ann->id, 'type' => 'purchase', 'amount_nano' => 5_000, 'created_at' => '2026-11-10 10:00:00']);
        LedgerEntry::factory()->create(['user_id' => $ann->id, 'type' => 'purchase', 'amount_nano' => 7_000, 'created_at' => '2026-10-31 23:59:59']);
        LedgerEntry::factory()->create(['user_id' => $ann->id, 'type' => 'grant', 'amount_nano' => 9_000, 'created_at' => '2026-11-10 10:00:00']);
        LedgerEntry::factory()->charge($generation, 100)->create(['created_at' => '2026-11-10 10:00:00']);
        LedgerEntry::factory()->create(['type' => 'purchase', 'amount_nano' => 2_000, 'created_at' => '2026-11-11 10:00:00']);

        $report = new ProfitReport;

        expect($report->creditSold(november())->equals(nano(7_000)))->toBeTrue()
            ->and($report->creditSold(november(), $ann->id)->equals(nano(5_000)))->toBeTrue()
            ->and($report->creditSold(Period::all())->equals(nano(14_000)))->toBeTrue();
    });

    it('is zero when nothing was bought', function () {
        expect((new ProfitReport)->creditSold(november())->isZero())->toBeTrue();
    });

    it('adds up the credit users still hold, never counting a user below zero', function () {
        $ann = User::factory()->create();
        $bob = User::factory()->create();
        $cy = User::factory()->create();
        LedgerEntry::factory()->create(['user_id' => $ann->id, 'amount_nano' => 5_000]);
        LedgerEntry::factory()->create(['user_id' => $bob->id, 'amount_nano' => 3_000]);
        $spent = Generation::factory()->create(['user_id' => $bob->id]);
        LedgerEntry::factory()->charge($spent, 1_000)->create();
        // Cy overspent: owes money, which is not credit anybody holds
        $overrun = Generation::factory()->create(['user_id' => $cy->id]);
        LedgerEntry::factory()->charge($overrun, 4_000)->create();

        $report = new ProfitReport;

        expect($report->creditHeld()->equals(nano(7_000)))->toBeTrue()
            ->and($report->creditHeld($bob->id)->equals(nano(2_000)))->toBeTrue()
            ->and($report->creditHeld($cy->id)->isZero())->toBeTrue();
    });
});

describe('ledger check', function () {
    it('says the ledger agrees when its charges equal the charges on the generations', function () {
        $first = succeededAt('2026-11-23 10:00:00', 1_000, 1_250);
        $second = succeededAt('2026-11-24 10:00:00', 4_000, 5_000);
        LedgerEntry::factory()->charge($first, 1_250)->create();
        LedgerEntry::factory()->charge($second, 5_000)->create();

        $check = (new ProfitReport)->ledgerCheck();

        expect($check->agrees())->toBeTrue()
            ->and($check->onGenerations->equals(nano(6_250)))->toBeTrue()
            ->and($check->inLedger->equals(nano(6_250)))->toBeTrue();
    });

    it('says the ledger disagrees when a charge is missing or different, and shows both sums', function (int $ledgerCharge) {
        $generation = succeededAt('2026-11-24 10:00:00', 1_000, 1_250);
        if ($ledgerCharge > 0) {
            LedgerEntry::factory()->charge($generation, $ledgerCharge)->create();
        }

        $check = (new ProfitReport)->ledgerCheck();

        expect($check->agrees())->toBeFalse()
            ->and($check->onGenerations->equals(nano(1_250)))->toBeTrue()
            ->and($check->inLedger->equals(nano($ledgerCharge)))->toBeTrue();
    })->with([
        'no charge in the ledger' => [0],
        'charge too small' => [1_249],
        'charge too big' => [1_251],
    ]);

    it('agrees trivially without any charge', function () {
        expect((new ProfitReport)->ledgerCheck()->agrees())->toBeTrue();
    });
});

describe('users to choose from', function () {
    it('lists everybody by name, also users who left', function () {
        User::factory()->create(['name' => 'Zed', 'email' => 'zed@example.com']);
        $gone = User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.com']);
        $gone->delete();

        $options = (new ProfitReport)->userOptions();

        expect(array_column($options, 'label'))->toBe(['Ann (ann@example.com)', 'Zed (zed@example.com)'])
            ->and($options[0]['id'])->toBe($gone->id);
    });
});
