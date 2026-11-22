<?php

declare(strict_types=1);

use App\Billing\Markup;
use App\Money\Usd;

mutates(Markup::class);

it('adds 25 percent to the provider cost', function (int $cost, int $charge) {
    expect(Usd::toInt((new Markup(25))->apply(Usd::nano($cost))))->toBe($charge);
})->with([
    'exact multiple of four' => [13_830_000, 17_287_500],
    'documented worst case' => [373_875_000, 467_343_750],
    'zero' => [0, 0],
    'one nanodollar rounds down' => [1, 1],      // 1.25 -> 1
    'two rounds half up' => [2, 3],              // 2.5  -> 3
    'three rounds up' => [3, 4],                 // 3.75 -> 4
    'five rounds down' => [5, 6],                // 6.25 -> 6
    'six rounds half up' => [6, 8],              // 7.5  -> 8
    'seven rounds up' => [7, 9],                 // 8.75 -> 9
    'ten rounds half up' => [10, 13],            // 12.5 -> 13
    'negative two ties away from zero' => [-2, -3],   // -2.5 -> -3
    'negative six ties away from zero' => [-6, -8],   // -7.5 -> -8
]);

it('never undercharges by a full nanodollar and never loses more than half', function () {
    for ($cost = 0; $cost <= 1_000; $cost++) {
        $charge = Usd::toInt((new Markup(25))->apply(Usd::nano($cost)));

        // |charge - cost * 5/4| <= 1/2, kept in integers: |4 * charge - 5 * cost| <= 2
        expect(abs(4 * $charge - 5 * $cost))->toBeLessThanOrEqual(2);
    }
});

it('stays exact for large amounts', function () {
    $cost = Usd::parse('1000000.000000001');

    expect(Usd::toInt((new Markup(25))->apply($cost)))->toBe(1_250_000_000_000_001);
});

it('applies the configured percent', function (int $percent, int $cost, int $charge) {
    expect(Usd::toInt((new Markup($percent))->apply(Usd::nano($cost))))->toBe($charge);
})->with([
    'no markup' => [0, 1_000, 1_000],
    'ten percent' => [10, 1_000, 1_100],
    'ten percent rounds half up' => [10, 5, 6],   // 5.5 -> 6
    'doubling' => [100, 7, 14],
]);

it('rejects a negative percent', function () {
    new Markup(-1);
})->throws(InvalidArgumentException::class);
