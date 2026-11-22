<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * Loads a response recorded from the live Pixelforge API (see tests/Fixtures/pixelforge).
 *
 * @return array<mixed>
 */
function pixelforgeFixture(string $name): array
{
    return json_decode(
        file_get_contents(__DIR__."/Fixtures/pixelforge/{$name}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}
