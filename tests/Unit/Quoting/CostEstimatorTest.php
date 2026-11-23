<?php

declare(strict_types=1);

use App\Money\Usd;
use App\Pixelforge\Api\Pricing;
use App\Pixelforge\Api\Rates;
use App\Pixelforge\Api\Usage;
use App\Quoting\CostEstimate;
use App\Quoting\CostEstimator;
use App\Quoting\Markup;

mutates(CostEstimator::class, CostEstimate::class);

function estimator(): CostEstimator
{
    return new CostEstimator(Pricing::fromArray(pixelforgeFixture('pricing')));
}

it('reproduces the documented worst case as the maximum', function () {
    $estimate = estimator()->estimate(str_repeat('a', 400), 'high', '1536x1024', referenceImages: 2);

    expect(Usd::format($estimate->max))->toBe('0.373875000');
});

it('derives minimum and expected from the documented ranges', function () {
    $estimate = estimator()->estimate(str_repeat('a', 400), 'high', '1536x1024', referenceImages: 2);

    // min: 85 text, 1530 image, 6450 output, 1000 reasoning
    expect(Usd::toInt($estimate->min))->toBe(286_225_000)
        // expected: 100 text, 1530 image, 6450 output, 4500 reasoning (midpoint)
        ->and(Usd::toInt($estimate->expected))->toBe(330_050_000);
});

it('keeps min <= expected <= max', function (string $quality, string $size, int $images, int $chars) {
    $estimate = estimator()->estimate(str_repeat('a', $chars), $quality, $size, $images);

    expect($estimate->min->lessThanOrEqual($estimate->expected))->toBeTrue()
        ->and($estimate->expected->lessThanOrEqual($estimate->max))->toBeTrue();
})->with(function () {
    foreach (['low', 'medium', 'high'] as $quality) {
        foreach (['1024x1024', '1536x1024', '1024x1536'] as $size) {
            foreach ([0, 4] as $images) {
                yield "$quality $size $images" => [$quality, $size, $images, 37];
            }
        }
    }
});

it('counts Unicode characters, not bytes', function () {
    $cyrillic = estimator()->estimate(str_repeat('Ё', 400), 'low', '1024x1024', 0);
    $ascii = estimator()->estimate(str_repeat('a', 400), 'low', '1024x1024', 0);

    expect(Usd::toInt($cyrillic->max))->toBe(Usd::toInt($ascii->max));
});

it('bounds every recorded generation between min and max', function (array $generation) {
    $estimate = estimator()->estimate(
        str_repeat('a', $generation['prompt_chars']),
        $generation['quality'],
        $generation['size'],
        $generation['reference_images'],
    );

    $actual = Rates::fromArray($generation['rates'])->costOf(Usage::fromArray($generation['usage']));

    expect($actual->greaterThanOrEqual($estimate->min))->toBeTrue()
        ->and($actual->lessThanOrEqual($estimate->max))->toBeTrue();
})->with(fn () => array_map(fn ($g) => [$g], pixelforgeFixture('succeeded_generations')));

it('applies the markup to every bound for the price shown to the user', function () {
    $estimate = estimator()->estimate(str_repeat('a', 400), 'high', '1536x1024', 2)
        ->withMarkup(new Markup(25));

    expect(Usd::toInt($estimate->max))->toBe(467_343_750)
        ->and(Usd::toInt($estimate->min))->toBe(357_781_250);
});

it('bounds the text tokens by the documented variance for every prompt length', function (int $percent) {
    // Only text tokens cost anything here (1 nanodollar each), so a cost equals a token count.
    $payload = pixelforgeFixture('pricing');
    $payload['tokens']['text_token_variance'] = $percent / 100;
    $payload['rates'] = ['input_text_tokens' => '0.001', 'input_image_tokens' => '0.000', 'output_tokens' => '0.000', 'reasoning_tokens' => '0.000'];
    $isolated = new CostEstimator(Pricing::fromArray($payload));

    for ($characters = 0; $characters <= 1_500; $characters++) {
        $estimated = (int) ceil($characters / 4);
        $estimate = $isolated->estimate(str_repeat('a', $characters), 'low', '1024x1024', 0);
        $min = Usd::toInt($estimate->min);
        $max = Usd::toInt($estimate->max);

        // min is the largest whole number not above (100 - p)% of the estimate; max the smallest not below (100 + p)%.
        $low = $estimated * (100 - $percent);
        $high = $estimated * (100 + $percent);

        expect($low)->toBeGreaterThanOrEqual(100 * $min)
            ->and($low)->toBeLessThan(100 * ($min + 1))
            ->and($high)->toBeLessThanOrEqual(100 * $max)
            ->and($high)->toBeGreaterThan(100 * ($max - 1))
            ->and(Usd::toInt($estimate->expected))->toBe($estimated);
    }
})->with([0, 1, 7, 15, 33]);
