<?php

declare(strict_types=1);

use App\Pixelforge\Client\CachedPricing;
use App\Pixelforge\Client\RetryLater;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

mutates(CachedPricing::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.pricing_fresh_seconds' => 300, 'pixelforge.pricing_stale_seconds' => 3600]);

    // What Pixelforge answers right now; tests change it between calls.
    $this->provider = (object) ['tokensPerImage' => 765, 'down' => false];
    Http::fake(['*/pricing' => function () {
        if ($this->provider->down) {
            return Http::response('', 503);
        }

        $payload = pixelforgeFixture('pricing');
        $payload['tokens']['per_reference_image'] = $this->provider->tokensPerImage;

        return Http::response($payload);
    }]);

    $this->pricing = app(CachedPricing::class);
});

/** Runs what the app does after it has answered a request, where the cache refreshes stale values. */
function runDeferredWork(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

it('asks Pixelforge once while the pricing is fresh', function () {
    $this->pricing->current();
    $this->travel(299)->seconds();
    $this->pricing->current();
    runDeferredWork();

    Http::assertSentCount(1);
});

it('serves the old pricing at once and refreshes it afterwards', function () {
    $this->pricing->current();
    $this->provider->tokensPerImage = 800;
    $this->travel(301)->seconds();

    expect($this->pricing->current()->tokensPerReferenceImage)->toBe(765);

    runDeferredWork();

    expect($this->pricing->current()->tokensPerReferenceImage)->toBe(800);
    Http::assertSentCount(2);
});

it('keeps serving the old pricing when Pixelforge is down', function () {
    $this->pricing->current();
    $this->provider->down = true;
    $this->travel(301)->seconds();

    $served = $this->pricing->current()->tokensPerReferenceImage;
    runDeferredWork();

    expect($served)->toBe(765)
        ->and($this->pricing->current()->tokensPerReferenceImage)->toBe(765);
});

it('gives up on a pricing that is too old', function () {
    $this->pricing->current();
    $this->provider->down = true;
    $this->travel(3601)->seconds();

    $this->pricing->current();
})->throws(RetryLater::class);

it('fails when there is no pricing yet and Pixelforge is down', function () {
    $this->provider->down = true;

    $this->pricing->current();
})->throws(RetryLater::class);

it('reads the pricing on a cache that stores values as text', function () {
    config(['cache.default' => 'database']);
    Cache::flush();

    $first = app(CachedPricing::class)->current();
    $second = app(CachedPricing::class)->current();

    expect($second->tokensPerReferenceImage)->toBe($first->tokensPerReferenceImage)->toBe(765);
    Http::assertSentCount(1);
});
