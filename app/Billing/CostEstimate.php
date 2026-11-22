<?php

declare(strict_types=1);

namespace App\Billing;

use Money\Money;

/**
 * What a generation can cost before it runs: reasoning and text tokens are only known afterwards,
 * so the cost is a range. `expected` assumes midpoint reasoning and the estimated text tokens.
 */
final readonly class CostEstimate
{
    public function __construct(
        public Money $min,
        public Money $expected,
        public Money $max,
    ) {}

    /**
     * The same range as the user pays it: every bound goes through the markup.
     */
    public function withMarkup(Markup $markup): self
    {
        return new self($markup->apply($this->min), $markup->apply($this->expected), $markup->apply($this->max));
    }
}
