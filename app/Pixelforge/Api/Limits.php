<?php

declare(strict_types=1);

namespace App\Pixelforge\Api;

use InvalidArgumentException;

/**
 * Request limits of POST /v1/generations that are not part of the pricing endpoint.
 */
final readonly class Limits
{
    public function __construct(
        public int $maxPromptCharacters,
        public int $maxReferenceImages,
    ) {
        if ($maxPromptCharacters < 1 || $maxReferenceImages < 0) {
            throw new InvalidArgumentException('Limits must allow at least one prompt character and a non-negative number of reference images.');
        }
    }
}
