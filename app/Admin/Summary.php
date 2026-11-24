<?php

declare(strict_types=1);

namespace App\Admin;

use Money\Money;

/**
 * The totals of a stretch of time: the succeeded generations (what they were charged and what
 * Pixelforge billed for them) and the ones that failed. A generation the user cancelled before it
 * was sent is not counted as a failure: it cost nothing and was their choice.
 */
final readonly class Summary
{
    public function __construct(
        /** Succeeded generations. */
        public int $generations,
        public int $failed,
        public Money $charged,
        public Money $providerCost,
    ) {}

    /** What we kept: charged to the users minus what Pixelforge billed us. */
    public function profit(): Money
    {
        return $this->charged->subtract($this->providerCost);
    }

    public function averagePrice(): ?Money
    {
        if ($this->generations === 0) {
            return null;
        }

        return $this->charged->divide($this->generations, Money::ROUND_HALF_UP);
    }

    /** Profit as a percentage of what was charged, one decimal ("20.0"). Null when nothing was charged. */
    public function marginPercent(): ?string
    {
        if ($this->charged->isZero()) {
            return null;
        }

        return self::percent($this->profit()->getAmount() / $this->charged->getAmount());
    }

    /** Succeeded generations as a percentage of those that either succeeded or failed. */
    public function successRate(): ?string
    {
        $finished = $this->generations + $this->failed;

        return $finished === 0 ? null : self::percent($this->generations / $finished);
    }

    /**
     * How much a value changed against an earlier one, as a signed percentage with one decimal
     * ("+50.0"). Null when the earlier value is zero, there is nothing to compare with.
     */
    public static function change(int|string $current, int|string $previous): ?string
    {
        $before = (float) $previous;

        if ($before === 0.0) {
            return null;
        }

        $percent = round(((float) $current - $before) / $before * 100, 1);

        return ($percent > 0 ? '+' : '').number_format($percent, 1, '.', '');
    }

    private static function percent(float $ratio): string
    {
        return number_format(round($ratio * 100, 1), 1, '.', '');
    }
}
