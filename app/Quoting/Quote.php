<?php

declare(strict_types=1);

namespace App\Quoting;

/**
 * What a generation is expected to cost the user, and the markup percent that price was built with.
 */
final readonly class Quote
{
    public function __construct(
        public CostEstimate $price,
        public int $markupPercent,
    ) {}
}
