<?php

declare(strict_types=1);

use App\Money\Usd;
use App\Pixelforge\Payload;
use App\Pixelforge\ProviderGeneration;
use App\Pixelforge\ProviderStatus;

mutates(ProviderGeneration::class, Payload::class);

it('reads a queued generation from a create response', function () {
    $data = pixelforgeFixture('generation_queued');
    $data['metadata'] = [ProviderGeneration::OUR_ID_METADATA_KEY => 'our-uuid'];

    $generation = ProviderGeneration::fromArray($data);

    expect($generation->id)->toBe($data['id'])
        ->and($generation->status)->toBe(ProviderStatus::Queued)
        ->and($generation->ourId)->toBe('our-uuid')
        ->and(Usd::toInt($generation->rates->output))->toBe(40_000)
        ->and($generation->usage)->toBeNull()
        ->and($generation->imageUrl)->toBeNull();
});

it('reads usage, image and expiry from a succeeded generation', function () {
    $data = pixelforgeFixture('generation_succeeded');

    $generation = ProviderGeneration::fromArray($data);

    expect($generation->status)->toBe(ProviderStatus::Succeeded)
        ->and($generation->usage->output)->toBe($data['usage']['output_tokens'])
        ->and($generation->imageUrl)->toBe($data['image_url'])
        ->and($generation->imageUrlExpiresAt->toIso8601ZuluString())->toBe($data['image_url_expires_at'])
        ->and($generation->completedAt)->not->toBeNull()
        ->and($generation->ourId)->toBeNull();
});

it('reads the error of a failed generation', function () {
    $generation = ProviderGeneration::fromArray(pixelforgeFixture('generation_failed'));

    expect($generation->status)->toBe(ProviderStatus::Failed)
        ->and($generation->errorCode)->toBe('generation_failed')
        ->and($generation->errorMessage)->toContain('not charged')
        ->and($generation->usage)->toBeNull();
});

it('rejects a generation object that cannot be trusted', function (callable $break) {
    $data = pixelforgeFixture('generation_succeeded');
    $break($data);

    ProviderGeneration::fromArray($data);
})->throws(InvalidArgumentException::class)->with([
    'succeeded without usage' => [function (array &$d) {
        $d['usage'] = null;
    }],
    'succeeded without an image' => [function (array &$d) {
        $d['image_url'] = null;
    }],
    'failed without an error' => [function (array &$d) {
        $d['status'] = 'failed';
        $d['error'] = null;
    }],
    'our own pending status' => [function (array &$d) {
        $d['status'] = 'pending';
    }],
    'unknown status' => [function (array &$d) {
        $d['status'] = 'cancelled';
    }],
    'status that is not text' => [function (array &$d) {
        $d['status'] = 5;
    }],
    'missing status' => [function (array &$d) {
        unset($d['status']);
    }],
    'missing id' => [function (array &$d) {
        unset($d['id']);
    }],
    'missing rates' => [function (array &$d) {
        unset($d['rates']);
    }],
    'usage with a missing count' => [function (array &$d) {
        unset($d['usage']['output_tokens']);
    }],
    'expiry that is not a date' => [function (array &$d) {
        $d['image_url_expires_at'] = 'tomorrow-ish';
    }],
    'expiry that is not text' => [function (array &$d) {
        $d['image_url_expires_at'] = 1_762_351_409;
    }],
    'empty image url' => [function (array &$d) {
        $d['image_url'] = '';
    }],
]);

it('names the field that is wrong', function () {
    $data = pixelforgeFixture('generation_queued');
    $data['metadata'] = [ProviderGeneration::OUR_ID_METADATA_KEY => 123];

    ProviderGeneration::fromArray($data);
})->throws(InvalidArgumentException::class, 'metadata.generation_uuid');

it('maps every provider status to our own status of the same name', function () {
    foreach (ProviderStatus::cases() as $status) {
        expect($status->toGenerationStatus()->value)->toBe($status->value);
    }
});
