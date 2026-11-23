<?php

declare(strict_types=1);

namespace App\Pixelforge\Api;

/**
 * Request limits of POST /v1/generations that are not part of the pricing endpoint.
 */
final readonly class Limits
{
    public function __construct(
        public int $maxPromptCharacters,
        public int $maxReferenceImages,
    ) {}
}
