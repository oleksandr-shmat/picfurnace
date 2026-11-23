<?php

declare(strict_types=1);

namespace App\Money;

use InvalidArgumentException;
use Money\Currencies\CurrencyList;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use OverflowException;

/**
 * The single place where this app creates, reads and persists money.
 *
 * Money is a moneyphp `Money` in US dollars whose minor unit is a nanodollar (1e-9 USD): Pixelforge
 * reports costs with nine decimals, so every cost is a whole number of minor units and sums stay
 * exact. Callers use `Money` for arithmetic and comparison and this class for the boundaries.
 */
final class Usd
{
    private const string CODE = 'USD';

    private const int MINOR_UNIT_DIGITS = 9;

    private static ?CurrencyList $currencies = null;

    public static function zero(): Money
    {
        return self::nano(0);
    }

    /**
     * An amount given as a whole number of nanodollars.
     */
    public static function nano(int|string $nanodollars): Money
    {
        $value = (string) $nanodollars;

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid nanodollar amount [{$value}]: expected an integer.");
        }

        /** @var numeric-string $value Checked by the pattern above. */
        return new Money($value, self::currency());
    }

    /**
     * Parses an exact decimal string such as "0.046125000" or "12.50".
     *
     * moneyphp's parser silently truncates extra decimals and accepts values such as "", " 1" and ".5",
     * so the input is validated first: more than nine decimals are refused instead of dropped.
     */
    public static function parse(string $decimal): Money
    {
        if (preg_match('/^-?\d+(?:\.\d{1,9})?$/', $decimal) !== 1) {
            throw new InvalidArgumentException("Invalid money amount [{$decimal}]: expected an exact decimal with at most nine decimals.");
        }

        return (new DecimalMoneyParser(self::currencies()))->parse($decimal, self::currency());
    }

    /**
     * @param  iterable<Money>  $amounts
     */
    public static function sum(iterable $amounts): Money
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = $total->add($amount);
        }

        return $total;
    }

    /**
     * Nine-decimal string such as "0.373875000", the format Pixelforge uses.
     */
    public static function format(Money $money): string
    {
        return (new DecimalMoneyFormatter(self::currencies()))->format($money);
    }

    /**
     * The amount as whole nanodollars for a bigint column. Money is arbitrary precision, a bigint is not.
     */
    public static function toInt(Money $money): int
    {
        $nano = filter_var($money->getAmount(), FILTER_VALIDATE_INT);

        if ($nano === false) {
            throw new OverflowException("Money amount [{$money->getAmount()}] does not fit a 64-bit integer.");
        }

        return $nano;
    }

    private static function currency(): Currency
    {
        return new Currency(self::CODE);
    }

    private static function currencies(): CurrencyList
    {
        return self::$currencies ??= new CurrencyList([self::CODE => self::MINOR_UNIT_DIGITS]);
    }
}
