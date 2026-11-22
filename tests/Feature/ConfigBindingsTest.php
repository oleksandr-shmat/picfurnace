<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\Markup;
use App\Billing\NoBalanceBilling;
use App\Pixelforge\Limits;
use App\Pixelforge\PixelforgeClient;

it('takes the markup percent from config', function () {
    config(['billing.markup_percent' => 40]);
    app()->forgetInstance(Markup::class);

    expect(app(Markup::class)->percent)->toBe(40);
});

it('defaults to 25 percent', function () {
    expect(app(Markup::class)->percent)->toBe(25);
});

it('takes the request limits from config', function () {
    config(['pixelforge.limits.max_reference_images' => 2, 'pixelforge.limits.max_prompt_characters' => 100]);
    app()->forgetInstance(Limits::class);

    $limits = app(Limits::class);

    expect($limits->maxReferenceImages)->toBe(2)->and($limits->maxPromptCharacters)->toBe(100);
});

it('uses the balance-free billing and a client built from config', function () {
    expect(app(Billing::class))->toBeInstanceOf(NoBalanceBilling::class)
        ->and(app(PixelforgeClient::class))->toBeInstanceOf(PixelforgeClient::class);
});
