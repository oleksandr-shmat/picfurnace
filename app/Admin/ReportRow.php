<?php

declare(strict_types=1);

namespace App\Admin;

use Carbon\CarbonImmutable;
use Money\Money;

/**
 * One line of the report: succeeded generations added up. `key` identifies the line (a UTC day, a
 * user id, a quality...), `label` is a readable name when the key is not enough. Lines of users
 * also say when the user last had something finished and what they have in credit.
 */
final readonly class ReportRow
{
    public function __construct(
        public string $key,
        public ?string $label,
        public int $generations,
        public Money $charged,
        public Money $providerCost,
        public ?CarbonImmutable $lastActivity = null,
        public ?Money $balance = null,
        public ?string $name = null,
        public ?string $email = null,
    ) {}

    /** What we kept: charged to the user minus what Pixelforge billed us. */
    public function profit(): Money
    {
        return $this->charged->subtract($this->providerCost);
    }
}
