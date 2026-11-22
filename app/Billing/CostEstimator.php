<?php

declare(strict_types=1);

namespace App\Billing;

use App\Pixelforge\Limits;
use App\Pixelforge\Pricing;
use App\Pixelforge\Usage;
use InvalidArgumentException;
use Money\Money;

/**
 * Estimates the provider cost of a generation before it is submitted.
 *
 * Output and reference-image tokens are exact. Text tokens are ceil(characters / 4) within the
 * documented variance and reasoning tokens fall in a range set by quality. The bounds are
 * rounded outwards, so the real cost can never fall outside [min, max].
 */
final class CostEstimator
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly CostCalculator $calculator,
        private readonly Limits $limits,
    ) {}

    public function estimate(string $prompt, string $quality, string $size, int $referenceImages): CostEstimate
    {
        if ($referenceImages < 0 || $referenceImages > $this->limits->maxReferenceImages) {
            throw new InvalidArgumentException("A generation takes between 0 and {$this->limits->maxReferenceImages} reference images.");
        }

        $perToken = $this->pricing->charactersPerTextToken;
        $variance = $this->pricing->textVariancePercent;

        // Unicode characters, not bytes, as documented.
        $estimatedText = self::ceilDiv(mb_strlen($prompt), $perToken);

        // The bounds are rounded outwards (min down, max up) so the real count stays inside.
        $minText = intdiv($estimatedText * (100 - $variance), 100);
        $maxText = self::ceilDiv($estimatedText * (100 + $variance), 100);

        [$minReasoning, $maxReasoning] = $this->pricing->reasoningRange($quality);
        $expectedReasoning = intdiv($minReasoning + $maxReasoning, 2);

        $imageTokens = $referenceImages * $this->pricing->tokensPerReferenceImage;
        $outputTokens = $this->pricing->outputTokens($quality, $size);

        return new CostEstimate(
            min: $this->cost($minText, $imageTokens, $outputTokens, $minReasoning),
            expected: $this->cost($estimatedText, $imageTokens, $outputTokens, $expectedReasoning),
            max: $this->cost($maxText, $imageTokens, $outputTokens, $maxReasoning),
        );
    }

    private static function ceilDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }

    private function cost(int $text, int $image, int $output, int $reasoning): Money
    {
        return $this->calculator->cost(new Usage($text, $image, $output, $reasoning), $this->pricing->rates);
    }
}
