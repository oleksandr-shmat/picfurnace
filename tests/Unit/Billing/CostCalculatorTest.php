<?php

declare(strict_types=1);

use App\Billing\CostCalculator;
use App\Money\Usd;
use App\Pixelforge\Rates;
use App\Pixelforge\Usage;

mutates(CostCalculator::class, Usage::class);

it('prices the documented worst case exactly', function () {
    $usage = new Usage(inputText: 115, inputImage: 1_530, output: 6_450, reasoning: 8_000);
    $rates = Rates::fromArray([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);

    expect(Usd::format((new CostCalculator)->cost($usage, $rates)))->toBe('0.373875000');
});

it('prices every recorded generation to the exact nanodollar', function (array $generation) {
    $usage = Usage::fromArray($generation['usage']);
    $rates = Rates::fromArray($generation['rates']);

    expect(Usd::toInt((new CostCalculator)->cost($usage, $rates)))->toBe($generation['cost_nano']);
})->with(fn () => array_map(fn ($g) => [$g], pixelforgeFixture('succeeded_generations')));

it('matches the provider usage report for the recorded day', function () {
    $total = Usd::sum(array_map(
        fn ($g) => (new CostCalculator)->cost(Usage::fromArray($g['usage']), Rates::fromArray($g['rates'])),
        pixelforgeFixture('succeeded_generations'),
    ));

    // GET /v1/usage for the same key and UTC day reported cost_usd "1.297507500" for these generations.
    expect(Usd::format($total))->toBe('1.297507500');
});

it('uses the rates stored with the generation, not any current rates', function () {
    $usage = new Usage(inputText: 10, inputImage: 0, output: 100, reasoning: 0);
    $old = Rates::fromArray(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']);
    $new = Rates::fromArray(['input_text_tokens' => '6.00', 'input_image_tokens' => '10.00', 'output_tokens' => '50.00', 'reasoning_tokens' => '12.50']);

    $calculator = new CostCalculator;

    expect(Usd::toInt($calculator->cost($usage, $old)))->not->toBe(Usd::toInt($calculator->cost($usage, $new)));
});
