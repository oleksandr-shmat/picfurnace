<?php

declare(strict_types=1);

namespace App\Pixelforge;

use Illuminate\Support\Facades\Cache;

/**
 * The current pricing, fetched from Pixelforge as rarely as possible.
 *
 * - Fresh (a few minutes): served from the cache, Pixelforge is not asked.
 * - Stale (up to an hour): the old pricing is served at once and one request, not all of them,
 *   refreshes it afterwards. If Pixelforge is down the old pricing stays in use.
 * - Missing (first use): fetched on the spot. If Pixelforge cannot be reached, the exception of the
 *   client propagates: without rates there is no honest estimate to show.
 */
final class CachedPricing
{
    private const string CACHE_KEY = 'pixelforge.pricing';

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly int $freshSeconds,
        private readonly int $staleSeconds,
    ) {}

    public function current(): Pricing
    {
        // The raw response is cached, not the Pricing object: caches do not restore our own classes.
        $payload = Cache::flexible(
            self::CACHE_KEY,
            [$this->freshSeconds, $this->staleSeconds],
            fn () => $this->client->pricingPayload(),
        );

        return Pricing::fromArray($payload);
    }
}
