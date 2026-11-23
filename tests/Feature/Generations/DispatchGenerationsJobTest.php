<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Generations\GenerationDispatcher;
use App\Jobs\DispatchGenerationsJob;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('sends the waiting generations to Pixelforge when a worker runs it', function () {
    Http::preventStrayRequests();
    Http::fake(['*/generations' => Http::response(pixelforgeFixture('generation_queued'), 202)]);
    $generation = Generation::factory()->create();

    (new DispatchGenerationsJob)->handle(app(GenerationDispatcher::class));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('queues one run for the same moment however often it is asked', function () {
    Queue::fake();
    Cache::flush();

    DispatchGenerationsJob::dispatch();
    DispatchGenerationsJob::dispatch();
    DispatchGenerationsJob::dispatch();

    $later = CarbonImmutable::parse('2026-11-23 15:00:10', 'UTC');
    DispatchGenerationsJob::dispatch()->delay($later);
    DispatchGenerationsJob::dispatch()->delay($later);

    Queue::assertPushed(DispatchGenerationsJob::class, 2);
});

it('lets runs for different moments through, so a delayed run never keeps out an immediate one', function () {
    Queue::fake();
    Cache::flush();

    DispatchGenerationsJob::dispatch()->delay(CarbonImmutable::parse('2026-11-23 15:00:10', 'UTC'));
    DispatchGenerationsJob::dispatch()->delay(CarbonImmutable::parse('2026-11-23 15:00:40', 'UTC'));
    DispatchGenerationsJob::dispatch();

    Queue::assertPushed(DispatchGenerationsJob::class, 3);
});

it('identifies a run by its moment', function () {
    $delayed = (new DispatchGenerationsJob)->delay(CarbonImmutable::parse('2026-11-23 15:00:10', 'UTC'));

    expect((new DispatchGenerationsJob)->uniqueId())->toBe('now')
        ->and($delayed->uniqueId())->toBe((string) CarbonImmutable::parse('2026-11-23 15:00:10', 'UTC')->getTimestamp());
});

it('has nothing to do when nothing is waiting', function () {
    Http::preventStrayRequests();

    (new DispatchGenerationsJob)->handle(app(GenerationDispatcher::class));

    Http::assertNothingSent();
});
