<?php

declare(strict_types=1);

use App\Admin\Period;
use Carbon\CarbonImmutable;

mutates(Period::class);

// A fixed "now" in the middle of a day, to show that presets work on whole UTC days.
function fixedNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-24 15:30:00', 'UTC');
}

function boundsOf(Period $period): array
{
    return [$period->from?->toDateTimeString(), $period->to?->toDateTimeString()];
}

it('covers all the time without limits', function () {
    $period = Period::all();

    expect($period->isAll())->toBeTrue()
        ->and(boundsOf($period))->toBe([null, null])
        ->and($period->days())->toBeNull()
        ->and($period->previous())->toBeNull();
});

it('works out the presets as whole UTC days ending with today', function (string $name, string $from, string $to, int $days) {
    $period = Period::preset($name, fixedNow());

    expect(boundsOf($period))->toBe([$from, $to])
        ->and($period->days())->toBe($days)
        ->and($period->isAll())->toBeFalse();
})->with([
    'today' => ['today', '2026-11-24 00:00:00', '2026-11-25 00:00:00', 1],
    'last 7 days' => ['7d', '2026-11-18 00:00:00', '2026-11-25 00:00:00', 7],
    'last 30 days' => ['30d', '2026-10-26 00:00:00', '2026-11-25 00:00:00', 30],
    'this month' => ['month', '2026-11-01 00:00:00', '2026-12-01 00:00:00', 30],
    'last month' => ['last_month', '2026-10-01 00:00:00', '2026-11-01 00:00:00', 31],
]);

it('knows the preset for all time', function () {
    expect(Period::preset('all', fixedNow())->isAll())->toBeTrue();
});

it('refuses a preset it does not know', function () {
    Period::preset('fortnight', fixedNow());
})->throws(InvalidArgumentException::class, 'fortnight');

it('uses the time zone UTC even when the clock is somewhere else', function () {
    $lateInNewYork = CarbonImmutable::parse('2026-11-24 22:00:00', 'America/New_York');

    // 22:00 in New York is 03:00 the next day in UTC
    expect(boundsOf(Period::preset('today', $lateInNewYork)))->toBe(['2026-11-25 00:00:00', '2026-11-26 00:00:00']);
});

it('takes a range of dates and includes the last day', function () {
    $period = Period::between(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-12'));

    expect(boundsOf($period))->toBe(['2026-11-10 00:00:00', '2026-11-13 00:00:00'])
        ->and($period->days())->toBe(3)
        ->and($period->lastDay()->toDateString())->toBe('2026-11-12');
});

it('takes one day as a range', function () {
    $day = CarbonImmutable::parse('2026-11-10');

    expect(Period::between($day, $day)->days())->toBe(1);
});

it('refuses a range that ends before it starts', function () {
    Period::between(CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-10'));
})->throws(InvalidArgumentException::class);

it('has the same number of days right before it as the previous period', function () {
    $previous = Period::preset('7d', fixedNow())->previous();

    expect(boundsOf($previous))->toBe(['2026-11-11 00:00:00', '2026-11-18 00:00:00'])
        ->and($previous->days())->toBe(7);
});

it('chains previous periods without gaps or overlaps', function () {
    $current = Period::between(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-12'));

    expect($current->previous()->to->equalTo($current->from))->toBeTrue()
        ->and($current->previous()->days())->toBe($current->days());
});

it('has no last day for all time', function () {
    expect(Period::all()->lastDay())->toBeNull();
});
