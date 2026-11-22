<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\ProviderGeneration;
use App\Pixelforge\ProviderStatus;
use App\Pixelforge\RequestRejected;
use App\Pixelforge\RetryLater;
use App\Pixelforge\UnexpectedResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

mutates(PixelforgeClient::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'sk_test_key']);

    $this->client = app(PixelforgeClient::class);
    // `user_id` is given so that `make` does not create a user: the generation is never saved.
    $this->generation = Generation::factory()->make([
        'user_id' => 1,
        'uuid' => '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10',
        'prompt' => 'A red cube',
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_image_urls' => [],
    ]);
});

it('creates a generation with our uuid as the idempotency key and in the metadata', function () {
    Http::fake(['*/generations' => Http::response(pixelforgeFixture('generation_queued'), 202)]);

    $created = $this->client->createGeneration($this->generation);

    expect($created->status)->toBe(ProviderStatus::Queued);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer sk_test_key')
        && $request->hasHeader('Idempotency-Key', '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10')
        && $request->hasHeader('User-Agent', 'image-studio/1.0')
        && $request->data() === [
            'prompt' => 'A red cube',
            'size' => '1024x1024',
            'quality' => 'low',
            'metadata' => [ProviderGeneration::OUR_ID_METADATA_KEY => '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10'],
        ]);
});

it('sends reference images only when there are some', function () {
    Http::fake(['*/generations' => Http::response(pixelforgeFixture('generation_queued'), 202)]);
    $this->generation->reference_image_urls = ['https://example.com/a.png'];

    $this->client->createGeneration($this->generation);

    Http::assertSent(fn (Request $request) => $request->data()['reference_images'] === ['https://example.com/a.png']);
});

it('fetches a generation by its provider id', function () {
    Http::fake(['*/generations/gen_1' => Http::response(pixelforgeFixture('generation_succeeded'))]);

    $found = $this->client->findGeneration('gen_1');

    expect($found->status)->toBe(ProviderStatus::Succeeded);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->hasHeader('Authorization'));
});

it('reads the pricing without an API key', function () {
    Http::fake(['*/pricing' => Http::response(pixelforgeFixture('pricing'))]);

    expect($this->client->pricing()->tokensPerReferenceImage)->toBe(765);

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

it('says when to retry limits and overload, using the Retry-After header', function (string $code, int $seconds) {
    Http::fake(['*' => Http::response(...pixelforgeErrorResponse($code))]);

    expect(fn () => $this->client->createGeneration($this->generation))
        ->toThrow(function (RetryLater $e) use ($code, $seconds) {
            expect($e->retryAfterSeconds)->toBe($seconds)->and($e->errorCode)->toBe($code);
        });
})->with([
    'rate limit' => ['rate_limit_exceeded', 19],
    'concurrency limit' => ['concurrency_limit_exceeded', 5],
    'overloaded' => ['overloaded', 2],
]);

it('reads Retry-After as whole seconds and falls back to a default otherwise', function (array $headers, int $seconds) {
    Http::fake(['*' => Http::response(pixelforgeErrorResponse('overloaded')[0], 503, $headers)]);

    expect(fn () => $this->client->createGeneration($this->generation))
        ->toThrow(function (RetryLater $e) use ($seconds) {
            expect($e->retryAfterSeconds)->toBe($seconds);
        });
})->with([
    'one second' => [['Retry-After' => '1'], 1],
    'nineteen seconds' => [['Retry-After' => '19'], 19],
    'missing' => [[], 5],
    'a date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], 5],
    'zero' => [['Retry-After' => '0'], 5],
]);

it('retries a server error later but not a client error', function (int $status, string $expected) {
    Http::fake(['*' => Http::response('', $status)]);

    expect(fn () => $this->client->createGeneration($this->generation))->toThrow($expected);
})->with([
    '499' => [499, UnexpectedResponse::class],
    '500' => [500, RetryLater::class],
    '502 bad gateway' => [502, RetryLater::class],
    '503' => [503, RetryLater::class],
]);

it('knows whether the provider can have created the generation', function (int $status, bool $nothingWasCreated) {
    Http::fake(['*' => Http::response('', $status)]);

    expect(fn () => $this->client->createGeneration($this->generation))
        ->toThrow(function (RetryLater $e) use ($nothingWasCreated) {
            expect($e->nothingWasCreated)->toBe($nothingWasCreated);
        });
})->with([
    'rate limit' => [429, true],
    'overloaded' => [503, true],
    'server error' => [500, false],
    'bad gateway' => [502, false],
]);

it('retries a lost connection later', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => $this->client->createGeneration($this->generation))
        ->toThrow(function (RetryLater $e) {
            expect($e->nothingWasCreated)->toBeFalse()->and($e->errorCode)->toBe('connection_failed');
        });
});

it('rejects a request Pixelforge will never accept', function (string $code) {
    [$body, $status] = pixelforgeErrorResponse($code);
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => $this->client->createGeneration($this->generation))
        ->toThrow(function (RequestRejected $e) use ($code, $body) {
            expect($e->errorCode)->toBe($code)->and($e->getMessage())->toBe($body['error']['message']);
        });
})->with(['content_policy_violation', 'validation_failed']);

it('does not guess on a response it cannot act on', function (int $status, mixed $body) {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => $this->client->createGeneration($this->generation))->toThrow(UnexpectedResponse::class);
})->with([
    'idempotency key conflict' => [409, pixelforgeErrorResponse('idempotency_key_reused')[0]],
    'bad API key' => [401, ['error' => ['code' => 'invalid_api_key', 'message' => 'No.']]],
    // Cloudflare answers with plain text when it blocks a client (error code 1010)
    'plain text refusal' => [403, 'error code: 1010'],
    'success without JSON' => [200, 'OK'],
    'success that is not a generation' => [202, ['hello' => 'world']],
]);

it('reads the error code and message when present and falls back to defaults', function (mixed $body, string $code, string $message) {
    Http::fake(['*' => Http::response($body, 503)]);

    expect(fn () => $this->client->createGeneration($this->generation))
        ->toThrow(function (RetryLater $e) use ($code, $message) {
            expect($e->errorCode)->toBe($code)->and($e->getMessage())->toBe($message);
        });
})->with([
    'code and message' => [['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 'overloaded', 'Busy.'],
    'code only' => [['error' => ['code' => 'overloaded']], 'overloaded', 'HTTP 503'],
    'message only' => [['error' => ['message' => 'Busy.']], 'unknown', 'Busy.'],
    'error that is not an object' => [['error' => 'Busy.'], 'unknown', 'HTTP 503'],
    'no JSON' => ['overloaded', 'unknown', 'HTTP 503'],
]);

it('explains why a generation response could not be read', function () {
    $succeededWithoutUsage = ['usage' => null] + pixelforgeFixture('generation_succeeded');
    Http::fake(['*' => Http::response($succeededWithoutUsage, 202)]);

    expect(fn () => $this->client->createGeneration($this->generation))
        ->toThrow(UnexpectedResponse::class, 'Unreadable generation response: Generation [gen_');
});

it('explains why a pricing response could not be read', function () {
    Http::fake(['*' => Http::response(['rates' => []])]);

    expect(fn () => $this->client->pricing())
        ->toThrow(UnexpectedResponse::class, 'Unreadable pricing response: Invalid [tokens]');
});
