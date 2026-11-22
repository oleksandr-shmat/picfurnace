<?php

declare(strict_types=1);

namespace App\Quoting;

use InvalidArgumentException;
use Money\Money;

/**
 * The price a user pays: provider cost plus a whole-percent markup (25% by default config).
 *
 * Provider costs are whole nanodollars, so only the markup can produce a fraction
 * (e.g. cost x 1.25 when the cost is not a multiple of four). The result is computed as
 * cost x (100 + percent) / 100 with a single rounding, half up (away from zero on ties), to a whole
 * nanodollar: at most half a nanodollar (5e-10 USD) away from the exact price.
 */
final readonly class Markup
{
    public function __construct(public int $percent)
    {
        if ($percent < 0) {
            throw new InvalidArgumentException('Markup percent cannot be negative.');
        }
    }

    public function apply(Money $providerCost): Money
    {
        return $providerCost
            ->multiply(100 + $this->percent)
            ->divide(100, Money::ROUND_HALF_UP);
    }
}
