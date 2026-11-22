<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Enums\GenerationStatus;
use App\Generations\GenerationReconciler;
use App\Models\Generation;
use App\Pixelforge\Client\UnexpectedResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

mutates(GenerationReconciler::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->billing = Mockery::mock(Billing::class)->shouldIgnoreMissing();
    app()->instance(Billing::class, $this->billing);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'));

    $this->reconciler = app(GenerationReconciler::class);
});

/** A generation that Pixelforge accepted and has not reported on for a while. */
function silentGeneration(array $attributes = []): Generation
{
    static $number = 0;
    $number++;

    return Generation::factory()->create($attributes + [
        'status' => GenerationStatus::Processing,
        'provider_id' => "gen_{$number}",
        'submitted_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(5),
    ]);
}

/** What Pixelforge answers about this generation. */
function providerAnswers(Generation $generation, string $fixture): void
{
    Http::fake(["*/generations/{$generation->provider_id}" => Http::response(array_replace(pixelforgeFixture($fixture), ['id' => $generation->provider_id]))]);
}

it('learns the result of a generation whose webhook never arrived', function () {
    $generation = silentGeneration();
    providerAnswers($generation, 'generation_succeeded');
    $this->billing->shouldReceive('settle')->once();

    expect($this->reconciler->reconcile())->toBe(1);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nano)->toBe(17_287_500);
});

it('fails a generation for free when Pixelforge says it failed', function () {
    $generation = silentGeneration();
    providerAnswers($generation, 'generation_failed');

    $this->reconciler->reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0);
});

it('changes nothing when Pixelforge has nothing new', function () {
    $generation = silentGeneration();
    providerAnswers($generation, 'generation_processing');

    expect($this->reconciler->reconcile())->toBe(0)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('asks only about generations that have been silent for three minutes', function () {
    $silent = silentGeneration(['updated_at' => now()->subMinutes(3)]);
    silentGeneration(['updated_at' => now()->subMinutes(3)->addSecond()]);
    providerAnswers($silent, 'generation_processing');

    $this->reconciler->reconcile();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/generations/{$silent->provider_id}"));
});

it('leaves alone what is finished, not yet sent, or unknown to Pixelforge', function (array $state) {
    silentGeneration($state);
    Http::fake();

    expect($this->reconciler->reconcile())->toBe(0);

    Http::assertNothingSent();
})->with([
    'succeeded' => [['status' => GenerationStatus::Succeeded, 'provider_id' => 'gen_s', 'provider_cost_nano' => 1, 'charged_nano' => 1]],
    'failed' => [['status' => GenerationStatus::Failed, 'provider_id' => 'gen_f', 'charged_nano' => 0]],
    'waiting in our queue' => [['status' => GenerationStatus::Pending, 'provider_id' => null]],
    'accepted but without an id yet' => [['status' => GenerationStatus::Queued, 'provider_id' => null]],
]);

it('also polls generations still queued at Pixelforge', function () {
    $generation = silentGeneration(['status' => GenerationStatus::Queued]);
    providerAnswers($generation, 'generation_processing');

    $this->reconciler->reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('asks about the quietest generations first and no more than ten at a time', function () {
    $oldest = silentGeneration(['updated_at' => now()->subHours(2)]);
    foreach (range(1, 11) as $ignored) {
        silentGeneration();
    }
    Http::fake(['*' => fn (Request $request) => Http::response(array_replace(pixelforgeFixture('generation_processing'), ['id' => basename($request->url())]))]);

    $this->reconciler->reconcile();

    Http::assertSentCount(10);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/generations/{$oldest->provider_id}"));
});

it('stops for this run when Pixelforge is limiting or down', function () {
    silentGeneration();
    silentGeneration();
    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429, ['Retry-After' => '19'])]);

    expect($this->reconciler->reconcile())->toBe(0);

    Http::assertSentCount(1);
});

it('reports an answer it cannot use and goes on with the others', function () {
    Exceptions::fake();
    $first = silentGeneration(['updated_at' => now()->subMinutes(8)]);
    $second = silentGeneration(['updated_at' => now()->subMinutes(6)]);
    Http::fake([
        "*/generations/{$first->provider_id}" => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation.']], 404),
        "*/generations/{$second->provider_id}" => Http::response(array_replace(pixelforgeFixture('generation_failed'), ['id' => $second->provider_id])),
    ]);

    expect($this->reconciler->reconcile())->toBe(1);

    Exceptions::assertReported(UnexpectedResponse::class);
    expect($second->refresh()->status)->toBe(GenerationStatus::Failed);
});

it('runs from the artisan command', function () {
    $generation = silentGeneration();
    providerAnswers($generation, 'generation_succeeded');

    $this->artisan('generations:reconcile')->expectsOutput('Generations updated: 1')->assertSuccessful();
});
