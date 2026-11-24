<?php

declare(strict_types=1);

namespace App\Admin;

use Money\Money;

/**
 * One line of the profit report: succeeded generations added up. `key` identifies the line (a UTC
 * day or a user id), `label` is a readable name when the key is not enough.
 */
final readonly class ReportRow
{
    public function __construct(
        public string $key,
        public ?string $label,
        public int $generations,
        public Money $charged,
        public Money $providerCost,
    ) {}

    /** What we kept: charged to the user minus what Pixelforge billed us. */
    public function profit(): Money
    {
        return $this->charged->subtract($this->providerCost);
    }
}
