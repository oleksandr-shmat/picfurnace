<?php

declare(strict_types=1);

use App\Pixelforge\Api\Usage;

mutates(Usage::class);

it('reads token counts from the usage object of a generation', function () {
    $usage = Usage::fromArray([
        'input_text_tokens' => 11,
        'input_image_tokens' => 765,
        'output_tokens' => 280,
        'reasoning_tokens' => 206,
        'total_tokens' => 1_262,
    ]);

    expect($usage->inputText)->toBe(11)
        ->and($usage->inputImage)->toBe(765)
        ->and($usage->output)->toBe(280)
        ->and($usage->reasoning)->toBe(206);
});

it('writes the counts in the API format', function () {
    expect((new Usage(11, 765, 280, 206))->toArray())->toBe([
        'input_text_tokens' => 11,
        'input_image_tokens' => 765,
        'output_tokens' => 280,
        'reasoning_tokens' => 206,
    ]);
});

it('rejects a negative count in any field', function (array $counts) {
    new Usage(...$counts);
})->throws(InvalidArgumentException::class, 'cannot be negative')->with([
    'input text' => [['inputText' => -1, 'inputImage' => 0, 'output' => 0, 'reasoning' => 0]],
    'input image' => [['inputText' => 0, 'inputImage' => -1, 'output' => 0, 'reasoning' => 0]],
    'output' => [['inputText' => 0, 'inputImage' => 0, 'output' => -1, 'reasoning' => 0]],
    'reasoning' => [['inputText' => 0, 'inputImage' => 0, 'output' => 0, 'reasoning' => -1]],
]);

it('rejects a usage object with a missing or non-integer count', function (array $usage) {
    Usage::fromArray($usage);
})->throws(InvalidArgumentException::class, 'integer')->with(function () {
    $valid = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 5];

    foreach (array_keys($valid) as $key) {
        $missing = $valid;
        unset($missing[$key]);
        yield "$key missing" => [$missing];
        yield "$key null" => [[$key => null] + $valid];
        yield "$key string" => [[$key => '5'] + $valid];
        yield "$key float" => [[$key => 5.0] + $valid];
    }
});
