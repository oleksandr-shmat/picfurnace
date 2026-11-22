<?php

declare(strict_types=1);

use App\Pixelforge\Limits;

mutates(Limits::class);

it('refuses limits that allow nothing', function (int $characters, int $images) {
    new Limits($characters, $images);
})->throws(InvalidArgumentException::class)->with([
    'no prompt characters' => [0, 4],
    'negative images' => [4_000, -1],
]);

it('accepts the smallest valid limits', function () {
    $limits = new Limits(1, 0);

    expect($limits->maxPromptCharacters)->toBe(1)->and($limits->maxReferenceImages)->toBe(0);
});
