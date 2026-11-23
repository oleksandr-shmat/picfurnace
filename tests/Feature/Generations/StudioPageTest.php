<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Generations\GenerationLifecycle;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    Cache::flush();

    $this->user = User::factory()->create();
});

it('shows the user their own generations, newest first, and no one else’s', function () {
    $older = Generation::factory()->create(['user_id' => $this->user->id]);
    $newer = Generation::factory()->create(['user_id' => $this->user->id]);
    Generation::factory()->create();

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Studio')
        ->has('generations', 2)
        ->where('generations.0.id', $newer->uuid)
        ->where('generations.1.id', $older->uuid)
        ->where('hasMore', false));
});

it('offers what Pixelforge offers and says what the user pays on top', function () {
    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('options.qualities', ['low', 'medium', 'high'])
        ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
        ->where('options.defaultQuality', 'medium')
        ->where('options.defaultSize', '1024x1024')
        ->where('options.maxPromptCharacters', 4000)
        ->where('options.maxReferenceImages', 4)
        ->where('options.maxUnfinishedGenerations', 5)
        ->where('options.markupPercent', 25));
});

it('tells how long images are kept, even when prices are not available', function () {
    Http::fake(['*/pricing' => fn () => throw new ConnectionException('down')]);

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('imageRetentionDays', 14)
        ->where('options', null));
});

it('still opens when Pixelforge cannot be reached, without options for the form', function () {
    Http::fake(['*/pricing' => fn () => throw new ConnectionException('down')]);

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('options', null)
        ->has('generations', 0));
});

it('shows a succeeded generation with the exact price and never the cost to us', function () {
    $generation = Generation::factory()->succeeded(providerCostNano: 13_830_000, chargedNano: 17_287_500)->create([
        'user_id' => $this->user->id,
        'estimated_price_min_nano' => 14_050_000,
        'estimated_price_expected_nano' => 17_000_000,
        'estimated_price_max_nano' => 21_887_500,
        'image_url' => 'https://images.pixelforge.test/a.png',
        'image_expires_at' => now()->addHours(5),
        'completed_at' => CarbonImmutable::parse('2026-11-20 08:30:00', 'UTC'),
    ]);

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('generations.0.status', 'succeeded')
        ->where('generations.0.charged', '0.017287500')
        ->where('generations.0.estimate', ['min' => '0.014050000', 'expected' => '0.017000000', 'max' => '0.021887500'])
        ->where('generations.0.image.url', route('generations.image', $generation->uuid))
        ->where('generations.0.image.availableUntil', '2026-12-04T08:30:00+00:00')
        ->where('generations.0.canCancel', false)
        ->missing('generations.0.providerCost')
        ->missing('generations.0.provider_cost_nano'));
});

it('says when the image is gone', function () {
    Generation::factory()->succeeded()->create([
        'user_id' => $this->user->id,
        'image_path' => null,
        'image_purged_at' => now(),
        'image_url' => 'https://images.pixelforge.test/a.png',
        'image_expires_at' => now()->subDay(),
    ]);

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('generations.0.image', ['url' => null, 'availableUntil' => null]));
});

it('shows a failure in words and marks a cancelled generation', function () {
    Generation::factory()->failed()->create(['user_id' => $this->user->id, 'failure_message' => 'The model could not produce an image. You were not charged.']);
    Generation::factory()->failed()->create(['user_id' => $this->user->id, 'failure_code' => GenerationLifecycle::CANCELLED_BY_USER, 'failure_message' => 'Cancelled before it was sent.']);

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('generations.1.failureMessage', 'The model could not produce an image. You were not charged.')
        ->where('generations.1.cancelled', false)
        ->where('generations.1.charged', '0.000000000')
        ->where('generations.1.image', null)
        ->where('generations.0.cancelled', true));
});

it('lets the user cancel only what has not been sent', function (array $state, bool $canCancel) {
    Generation::factory()->create($state + ['user_id' => $this->user->id]);

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('generations.0.canCancel', $canCancel));
})->with([
    'waiting in our queue' => [[], true],
    'being sent' => [['claimed_at' => now()], false],
    'queued at Pixelforge' => [['status' => GenerationStatus::Queued, 'provider_id' => 'gen_1'], false],
]);

it('shows a limited number of generations and says whether there are older ones', function () {
    Generation::factory()->count(3)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->get(route('dashboard', ['limit' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('generations', 2)
        ->where('hasMore', true));

    $this->actingAs($this->user)->get(route('dashboard', ['limit' => 3]))->assertInertia(fn (Assert $page) => $page
        ->has('generations', 3)
        ->where('hasMore', false));
});

it('keeps the size of the list within bounds', function (mixed $limit, int $shown) {
    Generation::factory()->count(105)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->get(route('dashboard', ['limit' => $limit]))->assertInertia(fn (Assert $page) => $page
        ->has('generations', $shown));
})->with([
    'default' => [null, 20],
    'too many' => [1000, 100],
    'zero' => [0, 1],
    'negative' => [-5, 1],
    'not a number' => ['abc', 1],
]);

it('is for signed-in users only', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('lets the author cancel a waiting generation', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->delete(route('generations.destroy', $generation->uuid))->assertRedirect();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0)
        ->and($generation->failure_code)->toBe(GenerationLifecycle::CANCELLED_BY_USER);
});

it('refuses to cancel a generation that was already sent', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id, 'claimed_at' => now()]);

    $this->actingAs($this->user)->delete(route('generations.destroy', $generation->uuid))
        ->assertSessionHasErrors(['generation' => 'This generation has already been sent and can no longer be cancelled.']);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
});

it('does not let anybody cancel somebody else’s generation, not even an admin', function (User $other) {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($other)->delete(route('generations.destroy', $generation->uuid))->assertForbidden();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'another user' => [fn () => User::factory()->create()],
    'an admin' => [fn () => User::factory()->admin()->create()],
]);

it('sends a guest to the login page when cancelling', function () {
    $generation = Generation::factory()->create();

    $this->delete(route('generations.destroy', $generation->uuid))->assertRedirect(route('login'));
});
