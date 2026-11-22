<?php

declare(strict_types=1);

use App\Money\Usd;

mutates(Usd::class);

it('parses exact decimal strings into nanodollars', function (string $decimal, int $nano) {
    expect(Usd::toInt(Usd::parse($decimal)))->toBe($nano);
})->with([
    'nine decimals' => ['0.373875000', 373_875_000],
    'fewer decimals' => ['12.50', 12_500_000_000],
    'whole dollars' => ['3', 3_000_000_000],
    'zero' => ['0.000000000', 0],
    'smallest unit' => ['0.000000001', 1],
    'negative' => ['-0.5', -500_000_000],
]);

it('refuses to silently drop decimals beyond nine', function (string $value) {
    Usd::parse($value);
})->throws(InvalidArgumentException::class)->with(['0.1234567891', '1.2345678901234', '0.0000000001']);

it('rejects malformed money strings', function (string $value) {
    Usd::parse($value);
})->throws(InvalidArgumentException::class)->with(['', 'abc', '1,5', '1e3', ' 1', '1.', '.5', '--1', '+1', '1.0.0']);

it('formats as a nine decimal string', function (int $nano, string $decimal) {
    expect(Usd::format(Usd::nano($nano)))->toBe($decimal);
})->with([
    [373_875_000, '0.373875000'],
    [0, '0.000000000'],
    [1, '0.000000001'],
    [1_297_507_500, '1.297507500'],
    [-500_000_000, '-0.500000000'],
    [12_500_000_000, '12.500000000'],
]);

it('sums a list, including an empty one', function () {
    expect(Usd::toInt(Usd::sum([Usd::nano(1), Usd::nano(2), Usd::nano(3)])))->toBe(6)
        ->and(Usd::sum([])->isZero())->toBeTrue();
});

it('creates amounts from integer nanodollars only', function (string|int $value) {
    Usd::nano($value);
})->throws(InvalidArgumentException::class)->with(['1.5', 'abc', '', '1e3']);

it('fails loudly when an amount does not fit a database bigint', function () {
    Usd::toInt(Usd::nano('9223372036854775808'));
})->throws(OverflowException::class);
