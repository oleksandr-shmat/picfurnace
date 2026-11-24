<?php

declare(strict_types=1);

use App\Admin\Summary;
use App\Money\Usd;

mutates(Summary::class);

function summaryOf(int $generations, int $failed, int $charged, int $cost): Summary
{
    return new Summary($generations, $failed, Usd::nano($charged), Usd::nano($cost));
}

it('keeps what it kept: charged minus the cost', function () {
    expect(summaryOf(1, 0, 1_250, 1_000)->profit()->equals(Usd::nano(250)))->toBeTrue()
        ->and(summaryOf(1, 0, 900, 1_000)->profit()->equals(Usd::nano(-100)))->toBeTrue();
});

it('averages the price per generation, rounding half up', function (int $generations, int $charged, int $expected) {
    expect(summaryOf($generations, 0, $charged, 0)->averagePrice()->equals(Usd::nano($expected)))->toBeTrue();
})->with([
    'exact' => [4, 10_000, 2_500],
    'rounds down' => [3, 10_000, 3_333],
    'half goes up' => [2, 5_001, 2_501],
    'one generation' => [1, 1_250, 1_250],
]);

it('has no average price without a generation', function () {
    expect(summaryOf(0, 0, 0, 0)->averagePrice())->toBeNull();
});

it('works out the margin as a percentage of what was charged', function (int $charged, int $cost, ?string $expected) {
    expect(summaryOf(1, 0, $charged, $cost)->marginPercent())->toBe($expected);
})->with([
    'a fifth' => [1_250, 1_000, '20.0'],
    'one decimal' => [3_000, 2_000, '33.3'],
    'no profit' => [1_000, 1_000, '0.0'],
    'a loss' => [1_000, 1_100, '-10.0'],
    'nothing charged' => [0, 0, null],
]);

it('works out the success rate without counting what cost nothing and was the user’s choice', function (int $succeeded, int $failed, ?string $expected) {
    expect(summaryOf($succeeded, $failed, 0, 0)->successRate())->toBe($expected);
})->with([
    'nine of ten' => [9, 1, '90.0'],
    'two of three' => [2, 1, '66.7'],
    'all' => [5, 0, '100.0'],
    'none' => [0, 4, '0.0'],
    'nothing yet' => [0, 0, null],
]);

it('shows the change against an earlier value as a signed percentage', function (int|string $current, int|string $previous, ?string $expected) {
    expect(Summary::change($current, $previous))->toBe($expected);
})->with([
    'up' => [150, 100, '+50.0'],
    'down' => [75, 100, '-25.0'],
    'one decimal' => [103, 100, '+3.0'],
    'unchanged' => [100, 100, '0.0'],
    'from nothing there is no figure' => [100, 0, null],
    'to nothing' => [0, 100, '-100.0'],
    'big nanodollar sums as strings' => ['1500000000000', '1000000000000', '+50.0'],
    'a huge rise has no thousands separator' => [1_100_000, 100, '+1099900.0'],
    'rounds to one decimal' => [5, 3, '+66.7'],
]);

it('shows a margin far below zero without a thousands separator', function () {
    // charged 1_000, cost 16_000: a loss of 1 500 percent of what was charged
    expect(summaryOf(1, 0, 1_000, 16_000)->marginPercent())->toBe('-1500.0');
});
