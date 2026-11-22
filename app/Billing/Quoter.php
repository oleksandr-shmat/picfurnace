<?php

declare(strict_types=1);

namespace App\Billing;

use App\Pixelforge\CachedPricing;
use App\Pixelforge\Limits;

/**
 * Prices a generation for the user before it is submitted: the estimated provider cost range plus
 * the markup. The live estimate in the form and the submit flow both use this.
 */
final class Quoter
{
    public function __construct(
        private readonly CachedPricing $pricing,
        private readonly CostCalculator $calculator,
        private readonly Limits $limits,
        private readonly Markup $markup,
    ) {}

    public function quote(string $prompt, string $quality, string $size, int $referenceImages): Quote
    {
        $estimator = new CostEstimator($this->pricing->current(), $this->calculator, $this->limits);

        $providerCost = $estimator->estimate($prompt, $quality, $size, $referenceImages);

        return new Quote($providerCost->withMarkup($this->markup), $this->markup->percent);
    }
}
