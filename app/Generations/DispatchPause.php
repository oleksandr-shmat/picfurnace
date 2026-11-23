<?php

declare(strict_types=1);

namespace App\Generations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * A pause of all sending to Pixelforge.
 *
 * Its limits (requests per minute, generations at once, generations per day) are shared by the
 * whole account, so when one of them is hit nothing may be sent until it is over, no matter which
 * user's generation hit it. The pause is a cache entry that expires when the pause ends.
 */
final class DispatchPause
{
    private const string CACHE_KEY = 'generation-dispatch-paused';

    /**
     * @return CarbonImmutable The moment the pause ends.
     */
    public function forSeconds(int $seconds): CarbonImmutable
    {
        return $this->until(CarbonImmutable::now()->addSeconds($seconds));
    }

    /**
     * @return CarbonImmutable The moment the pause ends.
     */
    public function until(CarbonImmutable $moment): CarbonImmutable
    {
        Cache::put(self::CACHE_KEY, $moment->toIso8601String(), $moment);

        return $moment;
    }

    public function isActive(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }
}
