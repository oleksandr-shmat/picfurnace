<?php

declare(strict_types=1);

use App\Money\Usd;
use App\Pixelforge\Pricing;
use App\Pixelforge\Rates;

mutates(Pricing::class, Rates::class);

it('converts per-million dollar rates to integer nanodollars per token', function () {
    $rates = Rates::fromArray([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);

    expect(Usd::toInt($rates->inputText))->toBe(5_000)
        ->and(Usd::toInt($rates->inputImage))->toBe(10_000)
        ->and(Usd::toInt($rates->output))->toBe(40_000)
        ->and(Usd::toInt($rates->reasoning))->toBe(12_500);
});

it('writes rates in the API format, so they read back unchanged', function () {
    $rates = Rates::fromArray(pixelforgeFixture('pricing')['rates']);

    expect($rates->toArray())->toBe([
        'input_text_tokens' => '5.000000000',
        'input_image_tokens' => '10.000000000',
        'output_tokens' => '40.000000000',
        'reasoning_tokens' => '12.500000000',
    ])->and(Rates::fromArray($rates->toArray())->toArray())->toBe($rates->toArray());
});

it('lists the qualities and sizes it knows', function () {
    $pricing = Pricing::fromArray(pixelforgeFixture('pricing'));

    expect($pricing->qualities())->toBe(['low', 'medium', 'high'])
        ->and($pricing->sizes())->toBe(['1024x1024', '1536x1024', '1024x1536']);
});

it('refuses rates with a missing key', function () {
    Rates::fromArray(['input_text_tokens' => '5.00']);
})->throws(InvalidArgumentException::class);

it('parses the pricing endpoint response', function () {
    $pricing = Pricing::fromArray(pixelforgeFixture('pricing'));

    expect(Usd::toInt($pricing->rates->output))->toBe(40_000)
        ->and($pricing->charactersPerTextToken)->toBe(4)
        ->and($pricing->textVariancePercent)->toBe(15)
        ->and($pricing->tokensPerReferenceImage)->toBe(765)
        ->and($pricing->outputTokens('high', '1536x1024'))->toBe(6_450)
        ->and($pricing->outputTokens('low', '1024x1024'))->toBe(280)
        ->and($pricing->reasoningRange('medium'))->toBe([200, 2_000])
        ->and($pricing->reasoningRange('high'))->toBe([1_000, 8_000]);
});

it('rejects an unknown quality or size', function () {
    $pricing = Pricing::fromArray(pixelforgeFixture('pricing'));

    $pricing->outputTokens('ultra', '1024x1024');
})->throws(InvalidArgumentException::class);

it('accepts a zero variance and an integer ratio', function (int|float $ratio, int $percent) {
    $payload = pixelforgeFixture('pricing');
    $payload['tokens']['text_token_variance'] = $ratio;

    expect(Pricing::fromArray($payload)->textVariancePercent)->toBe($percent);
})->with([
    'zero integer' => [0, 0],
    'zero float' => [0.0, 0],
    'five percent' => [0.05, 5],
    'one hundred percent' => [1, 100],
    // 0.29 * 100 and 0.57 * 100 are 28.999999999999996 and 56.99999999999999 in floating point
    'twenty nine percent' => [0.29, 29],
    'fifty seven percent' => [0.57, 57],
]);

it('rejects an invalid rate', function (array $override) {
    Rates::fromArray($override + [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);
})->throws(InvalidArgumentException::class)->with([
    'fraction of a nanodollar per token' => [['input_text_tokens' => '5.0001']],
    'negative' => [['input_text_tokens' => '-5.00']],
    'integer instead of a string' => [['input_text_tokens' => 5]],
    'float instead of a string' => [['input_text_tokens' => 5.0]],
    'null' => [['input_text_tokens' => null]],
]);

it('rejects an invalid pricing payload', function (callable $break) {
    $payload = pixelforgeFixture('pricing');
    $break($payload);

    Pricing::fromArray($payload);
})->throws(InvalidArgumentException::class)->with([
    'missing reasoning section' => [function (array &$p) {
        unset($p['tokens']['reasoning']);
    }],
    'negative output tokens' => [function (array &$p) {
        $p['tokens']['output']['low']['1024x1024'] = -1;
    }],
    'string output tokens' => [function (array &$p) {
        $p['tokens']['output']['low']['1024x1024'] = '280';
    }],
    'negative reasoning minimum' => [function (array &$p) {
        $p['tokens']['reasoning']['low']['min'] = -1;
    }],
    'missing reasoning maximum' => [function (array &$p) {
        unset($p['tokens']['reasoning']['low']['max']);
    }],
    'non-integer image tokens' => [function (array &$p) {
        $p['tokens']['per_reference_image'] = '765';
    }],
    'variance that is not a whole percent' => [function (array &$p) {
        $p['tokens']['text_token_variance'] = 0.155;
    }],
    'negative variance' => [function (array &$p) {
        $p['tokens']['text_token_variance'] = -0.15;
    }],
    'smallest negative variance' => [function (array &$p) {
        $p['tokens']['text_token_variance'] = -0.01;
    }],
    'variance as string' => [function (array &$p) {
        $p['tokens']['text_token_variance'] = '0.15';
    }],
]);
