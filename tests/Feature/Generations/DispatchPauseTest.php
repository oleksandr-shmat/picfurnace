<?php

declare(strict_types=1);

use App\Generations\DispatchPause;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

mutates(DispatchPause::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00:00', 'UTC'));
    $this->pause = new DispatchPause;
});

it('is not paused until somebody pauses it', function () {
    expect($this->pause->isActive())->toBeFalse();
});

it('stays paused for the given number of seconds and not longer', function () {
    $this->pause->forSeconds(10);

    expect($this->pause->isActive())->toBeTrue();

    $this->travel(9)->seconds();
    expect($this->pause->isActive())->toBeTrue();

    $this->travel(2)->seconds();
    expect($this->pause->isActive())->toBeFalse();
});

it('stays paused until the given moment', function () {
    $this->pause->until(CarbonImmutable::parse('2026-11-24 00:00:00', 'UTC'));

    $this->travelTo(CarbonImmutable::parse('2026-11-23 23:59:59', 'UTC'));
    expect($this->pause->isActive())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:00:00', 'UTC'));
    expect($this->pause->isActive())->toBeFalse();
});
