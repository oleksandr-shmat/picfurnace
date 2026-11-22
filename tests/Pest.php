<?php

declare(strict_types=1);

use App\Pixelforge\Api\ProviderGeneration;
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

/**
 * A recorded Pixelforge error as [body, status, headers] for Http::response(...).
 *
 * @return array{0: array<string, mixed>, 1: int, 2: array<string, string>}
 */
function pixelforgeErrorResponse(string $code): array
{
    $error = pixelforgeFixture('errors')[$code];

    return [$error['body'], $error['status'], array_filter(['Retry-After' => $error['retry_after']])];
}

/**
 * A generation as Pixelforge reports it, built from a recorded response with some fields replaced.
 *
 * @param  array<string, mixed>  $overrides
 */
function providerSnapshot(string $fixture, array $overrides = []): ProviderGeneration
{
    return ProviderGeneration::fromArray(array_replace(pixelforgeFixture($fixture), $overrides));
}
