<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Enums\GenerationStatus;
use App\Generations\GenerationLifecycle;
use App\Jobs\DispatchGenerationsJob;
use App\Models\Generation;
use App\Money\Usd;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Money\Money;

mutates(GenerationLifecycle::class);

/**
 * Provider cost of the recorded `generation_succeeded` response, worked out by hand:
 * 11 x 5 + 280 x 40 + 206 x 12.5 thousandths of a dollar per token = 13_830_000 nanodollars.
 * With a 25% markup the user pays 17_287_500, with 40% 19_362_000.
 */
const RECORDED_PROVIDER_COST = 13_830_000;

beforeEach(function () {
    $this->billing = Mockery::mock(Billing::class)->shouldIgnoreMissing();
    app()->instance(Billing::class, $this->billing);
    $this->lifecycle = app(GenerationLifecycle::class);
});

it('records the provider accepting a generation', function () {
    $generation = Generation::factory()->create(['claimed_at' => now()]);

    $applied = $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_queued', ['id' => 'gen_1']));

    $generation->refresh();
    expect($applied)->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_1')
        ->and($generation->rates['output_tokens'])->toBe('40.000000000')
        ->and($generation->submitted_at)->not->toBeNull()
        ->and($generation->charged_nano)->toBeNull();
});

it('lets the next waiting generation start as soon as one finishes, and not before', function (string $fixture, int $pushed) {
    Queue::fake();
    $generation = Generation::factory()->create(['claimed_at' => now()]);

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot($fixture, ['id' => 'gen_1']));

    Queue::assertPushed(DispatchGenerationsJob::class, $pushed);
})->with([
    'a success frees a slot' => ['generation_succeeded', 1],
    'a failure frees a slot' => ['generation_failed', 1],
    'processing does not' => ['generation_processing', 0],
    'queued does not' => ['generation_queued', 0],
]);

it('does not start anything for a report it ignored', function () {
    $generation = Generation::factory()->create(['claimed_at' => now()]);
    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded', ['id' => 'gen_1']));
    Queue::fake();

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded', ['id' => 'gen_1']));

    Queue::assertNothingPushed();
});

it('keeps the time the provider first accepted the generation', function () {
    $accepted = now()->subMinute()->startOfSecond();
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_1', 'submitted_at' => $accepted]);

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_processing', ['id' => 'gen_1']));

    expect($generation->refresh()->submitted_at->equalTo($accepted))->toBeTrue();
});

it('charges the provider cost plus the markup of the generation when it succeeds', function (int $markupPercent, int $charged) {
    $generation = Generation::factory()->create(['markup_percent' => $markupPercent, 'claimed_at' => now()]);

    $this->billing->shouldReceive('settle')->once()
        ->withArgs(fn (Generation $g, Money $price) => Usd::toInt($price) === $charged);

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nano)->toBe(RECORDED_PROVIDER_COST)
        ->and($generation->charged_nano)->toBe($charged)
        ->and($generation->usage['output_tokens'])->toBe(280)
        ->and($generation->image_url)->not->toBeNull()
        ->and($generation->image_expires_at)->not->toBeNull()
        ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-22T05:32:26Z');
})->with([
    '25 percent' => [25, 17_287_500],
    '40 percent' => [40, 19_362_000],
    'no markup' => [0, RECORDED_PROVIDER_COST],
]);

it('charges nothing and releases the generation when it fails', function () {
    $generation = Generation::factory()->create(['claimed_at' => now()]);

    $this->billing->shouldReceive('release')->once();
    $this->billing->shouldNotReceive('settle');

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_failed'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0)
        ->and($generation->provider_cost_nano)->toBeNull()
        ->and($generation->failure_code)->toBe('generation_failed')
        ->and($generation->failure_message)->toContain('not charged');
});

it('charges a success only once, however often it is reported', function () {
    $generation = Generation::factory()->create(['claimed_at' => now()]);
    $this->billing->shouldReceive('settle')->once();

    $first = $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded'));
    $second = $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded'));

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($generation->refresh()->charged_nano)->toBe(17_287_500);
});

it('ignores an older report that arrives after the result', function (string $late) {
    $generation = Generation::factory()->create(['claimed_at' => now()]);
    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded', ['id' => 'gen_1']));

    $applied = $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot($late, ['id' => 'gen_1']));

    expect($applied)->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nano)->toBe(17_287_500);
})->with(['generation_processing', 'generation_queued']);

it('never turns a failure into a charge or a charge into a failure', function (string $first, string $second, GenerationStatus $stays) {
    $generation = Generation::factory()->create(['claimed_at' => now()]);
    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot($first, ['id' => 'gen_1']));

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot($second, ['id' => 'gen_1']));

    expect($generation->refresh()->status)->toBe($stays);
})->with([
    'failed then succeeded' => ['generation_failed', 'generation_succeeded', GenerationStatus::Failed],
    'succeeded then failed' => ['generation_succeeded', 'generation_failed', GenerationStatus::Succeeded],
]);

it('refuses a report from another provider generation', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_1', 'status' => GenerationStatus::Queued]);

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_processing', ['id' => 'gen_2']));
})->throws(LogicException::class, 'already linked to [gen_1]');

it('cancels a generation that has not been sent, for free', function () {
    $generation = Generation::factory()->create();
    $this->billing->shouldReceive('release')->once();

    expect($this->lifecycle->cancel($generation))->toBeTrue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0)
        ->and($generation->failure_code)->toBe(GenerationLifecycle::CANCELLED_BY_USER);
});

it('does not cancel a generation once it may exist at the provider', function (array $state) {
    $generation = Generation::factory()->create($state);
    $this->billing->shouldNotReceive('release');

    expect($this->lifecycle->cancel($generation))->toBeFalse()
        ->and($generation->refresh()->status->value)->toBe(($state['status'] ?? GenerationStatus::Pending)->value);
})->with([
    'claimed for sending' => [['claimed_at' => now()]],
    'queued at the provider' => [['status' => GenerationStatus::Queued, 'provider_id' => 'gen_1']],
]);

it('gives up on a generation the provider never accepted, for free', function () {
    $generation = Generation::factory()->create(['claimed_at' => now()]);
    $this->billing->shouldReceive('release')->once();

    expect($this->lifecycle->failBeforeSubmission($generation, 'content_policy_violation', 'Blocked.'))->toBeTrue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0)
        ->and($generation->failure_code)->toBe('content_policy_violation')
        ->and($generation->failure_message)->toBe('Blocked.');
});

it('does not give up on a generation that already moved on', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_1']);

    expect($this->lifecycle->failBeforeSubmission($generation, 'x', 'y'))->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('logs loudly when Pixelforge completes a generation that we had failed', function () {
    $generation = Generation::factory()->failed()->create(['failure_code' => 'provider_unavailable']);

    Log::shouldReceive('critical')->once()->with(Mockery::type('string'), [
        'generation' => $generation->uuid,
        'failure_code' => 'provider_unavailable',
    ]);

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded', ['id' => 'gen_1']));

    expect($generation->refresh()->charged_nano)->toBe(0);
});

it('stays quiet about reports that are merely late', function (string $first, string $late) {
    $generation = Generation::factory()->create(['claimed_at' => now()]);
    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot($first, ['id' => 'gen_1']));

    Log::shouldReceive('critical')->never();

    $this->lifecycle->applyProviderSnapshot($generation, providerSnapshot($late, ['id' => 'gen_1']));
})->with([
    'processing after success' => ['generation_succeeded', 'generation_processing'],
    'failure after success' => ['generation_succeeded', 'generation_failed'],
    'processing after failure' => ['generation_failed', 'generation_processing'],
]);
