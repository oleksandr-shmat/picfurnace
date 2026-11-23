<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Generations\DispatchGenerationsJob;
use App\Generations\GenerationDispatcher;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;

it('sends the waiting generations to Pixelforge when a worker runs it', function () {
    Http::preventStrayRequests();
    Http::fake(['*/generations' => Http::response(pixelforgeFixture('generation_queued'), 202)]);
    $generation = Generation::factory()->create();

    (new DispatchGenerationsJob)->handle(app(GenerationDispatcher::class));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('has nothing to do when nothing is waiting', function () {
    Http::preventStrayRequests();

    (new DispatchGenerationsJob)->handle(app(GenerationDispatcher::class));

    Http::assertNothingSent();
});
