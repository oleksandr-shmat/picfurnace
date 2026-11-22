<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Generations\GenerationDispatcher;
use App\Generations\GenerationLifecycle;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Client\UnexpectedResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

mutates(GenerationDispatcher::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00:00', 'UTC'));
    $this->dispatcher = app(GenerationDispatcher::class);
});

/**
 * From now on Pixelforge accepts every request and answers with a new generation id. Earlier fakes and
 * the requests recorded so far are forgotten: the first fake that matches would otherwise win.
 */
function pixelforgeAcceptsEverything(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();

    $count = 0;

    Http::fake(['*/generations' => function () use (&$count) {
        $count++;

        return Http::response(array_replace(pixelforgeFixture('generation_queued'), ['id' => "gen_{$count}"]), 202);
    }]);
}

function waitingGeneration(array $attributes = []): Generation
{
    return Generation::factory()->create($attributes);
}

function sentUuids(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])->all();
}

it('sends a waiting generation and records that Pixelforge accepted it', function () {
    pixelforgeAcceptsEverything();
    $generation = waitingGeneration();

    $this->dispatcher->dispatchDue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_1')
        ->and($generation->claimed_at)->not->toBeNull();
    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->uuid));
});

it('sends at most one generation per user, the oldest first', function () {
    pixelforgeAcceptsEverything();
    $user = User::factory()->create();
    $older = waitingGeneration(['user_id' => $user->id, 'created_at' => now()->subMinutes(2)]);
    waitingGeneration(['user_id' => $user->id, 'created_at' => now()->subMinute()]);

    $this->dispatcher->dispatchDue();

    expect(sentUuids())->toBe([$older->uuid]);
});

it('serves users in turn instead of letting one user fill every slot', function () {
    pixelforgeAcceptsEverything();
    $busy = User::factory()->create();
    $other = User::factory()->create();
    $busyFirst = waitingGeneration(['user_id' => $busy->id, 'created_at' => now()->subMinutes(4)]);
    waitingGeneration(['user_id' => $busy->id, 'created_at' => now()->subMinutes(3)]);
    waitingGeneration(['user_id' => $busy->id, 'created_at' => now()->subMinutes(2)]);
    $otherFirst = waitingGeneration(['user_id' => $other->id, 'created_at' => now()->subMinute()]);

    $this->dispatcher->dispatchDue();

    expect(sentUuids())->toBe([$busyFirst->uuid, $otherFirst->uuid]);
});

it('keeps within the number of generations Pixelforge allows at once', function (int $active, int $sent) {
    pixelforgeAcceptsEverything();
    for ($i = 1; $i <= $active; $i++) {
        waitingGeneration(['status' => $i % 2 ? GenerationStatus::Queued : GenerationStatus::Processing, 'provider_id' => "gen_active_{$i}"]);
    }
    for ($i = 1; $i <= 3; $i++) {
        waitingGeneration();
    }

    $this->dispatcher->dispatchDue();

    expect(Http::recorded())->toHaveCount($sent);
})->with([
    'nothing active' => [0, 3],
    'two active' => [2, 1],
    'all slots taken' => [3, 0],
    'more active than allowed' => [4, 0],
]);

it('lets a user wait while one of their generations is active', function () {
    pixelforgeAcceptsEverything();
    $user = User::factory()->create();
    waitingGeneration(['user_id' => $user->id, 'status' => GenerationStatus::Processing, 'provider_id' => 'gen_active']);
    waitingGeneration(['user_id' => $user->id]);

    $this->dispatcher->dispatchDue();

    expect(Http::recorded())->toHaveCount(0);
});

it('does not send a generation before its retry time', function () {
    pixelforgeAcceptsEverything();
    $generation = waitingGeneration(['next_attempt_at' => now()->addSeconds(10)]);

    $this->dispatcher->dispatchDue();
    expect(Http::recorded())->toHaveCount(0);

    $this->travel(11)->seconds();
    $this->dispatcher->dispatchDue();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('retries a generation whose earlier attempt was cut short', function () {
    pixelforgeAcceptsEverything();
    $firstClaim = now()->subMinutes(5)->startOfSecond();
    $generation = waitingGeneration(['claimed_at' => $firstClaim]);

    $this->dispatcher->dispatchDue();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->claimed_at->equalTo($firstClaim))->toBeTrue();
});

it('does nothing while another run is working', function () {
    pixelforgeAcceptsEverything();
    waitingGeneration();
    $otherRun = Cache::lock('generation-dispatch', 10);
    $otherRun->get();

    $this->dispatcher->dispatchDue();

    expect(Http::recorded())->toHaveCount(0);
});

it('pauses every user when a limit shared by the account is hit', function (string $code) {
    $response = pixelforgeErrorResponse($code);
    Http::fake(['*/generations' => Http::response(...$response)]);
    $first = waitingGeneration(['created_at' => now()->subMinutes(2)]);
    $second = waitingGeneration(['created_at' => now()->subMinute()]);

    $this->dispatcher->dispatchDue();

    // only the first request was made, and the generation can still be cancelled: nothing was created
    expect(Http::recorded())->toHaveCount(1)
        ->and($first->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($first->claimed_at)->toBeNull()
        ->and($first->attempts)->toBe(0)
        ->and($second->refresh()->claimed_at)->toBeNull();

    // still paused just before Retry-After is over, sending again right after
    pixelforgeAcceptsEverything();
    $this->travel($response[2]['Retry-After'] - 1)->seconds();
    $this->dispatcher->dispatchDue();
    expect(Http::recorded())->toHaveCount(0);

    $this->travel(2)->seconds();
    $this->dispatcher->dispatchDue();
    expect(Http::recorded())->toHaveCount(2);
})->with(['rate_limit_exceeded', 'concurrency_limit_exceeded']);

it('waits for the next UTC day when the daily limit is used up', function () {
    Http::fake(['*/generations' => Http::response(['error' => ['code' => 'daily_limit_exceeded', 'message' => 'Quota used up.']], 429)]);
    $generation = waitingGeneration();

    $this->dispatcher->dispatchDue();
    expect(Http::recorded())->toHaveCount(1);

    pixelforgeAcceptsEverything();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 23:59:59', 'UTC'));
    $this->dispatcher->dispatchDue();
    expect(Http::recorded())->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:00:00', 'UTC'));
    $this->dispatcher->dispatchDue();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('backs off further after each failed attempt and honours a longer Retry-After', function (int $failedBefore, string $retryAfter, int $waitSeconds) {
    Http::fake(['*/generations' => Http::response(pixelforgeErrorResponse('overloaded')[0], 503, ['Retry-After' => $retryAfter])]);
    $generation = waitingGeneration(['attempts' => $failedBefore]);

    $this->dispatcher->dispatchDue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->attempts)->toBe($failedBefore + 1)
        ->and($generation->next_attempt_at->equalTo(now()->addSeconds($waitSeconds)))->toBeTrue()
        ->and($generation->claimed_at)->toBeNull();
})->with([
    'first failure' => [0, '2', 5],
    'second failure' => [1, '2', 10],
    'third failure' => [2, '2', 20],
    'fourth failure' => [3, '2', 40],
    'Retry-After longer than the backoff' => [0, '60', 60],
]);

it('gives up after the fifth failed attempt, for free', function () {
    Http::fake(['*/generations' => Http::response(pixelforgeErrorResponse('overloaded')[0], 503)]);
    $generation = waitingGeneration(['attempts' => 4]);

    $this->dispatcher->dispatchDue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->failure_code)->toBe('provider_unavailable')
        ->and($generation->charged_nano)->toBe(0);
});

it('keeps a generation that may exist at the provider from being cancelled for free', function () {
    Http::fake(['*/generations' => fn () => throw new ConnectionException('timeout')]);
    $generation = waitingGeneration();

    $this->dispatcher->dispatchDue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->attempts)->toBe(1)
        ->and($generation->claimed_at)->not->toBeNull()
        ->and(app(GenerationLifecycle::class)->cancel($generation))->toBeFalse();
});

it('fails a generation the provider refuses for good, for free', function (string $code) {
    Http::fake(['*/generations' => Http::response(...pixelforgeErrorResponse($code))]);
    $generation = waitingGeneration();

    $this->dispatcher->dispatchDue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->failure_code)->toBe($code)
        ->and($generation->failure_message)->not->toBeEmpty()
        ->and($generation->charged_nano)->toBe(0);
})->with(['content_policy_violation', 'validation_failed']);

it('reports an answer it cannot read and tries again later', function () {
    Exceptions::fake();
    Http::fake(['*/generations' => Http::response('error code: 1010', 403)]);
    $generation = waitingGeneration();

    $this->dispatcher->dispatchDue();

    Exceptions::assertReported(UnexpectedResponse::class);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->attempts)->toBe(1)
        ->and($generation->claimed_at)->not->toBeNull();
});

it('does not move a generation back when its result arrived before our request returned', function () {
    $generation = waitingGeneration();
    Http::fake(['*/generations' => function () use ($generation) {
        app(GenerationLifecycle::class)->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded', ['id' => 'gen_early']));

        return Http::response(array_replace(pixelforgeFixture('generation_queued'), ['id' => 'gen_early']), 202);
    }]);

    $this->dispatcher->dispatchDue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nano)->toBe(17_287_500);
});

it('sends waiting generations from the artisan command', function () {
    pixelforgeAcceptsEverything();
    $generation = waitingGeneration();

    $this->artisan('generations:dispatch')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});
