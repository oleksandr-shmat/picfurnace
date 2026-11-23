<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\InsufficientBalance;
use App\Enums\GenerationStatus;
use App\Generations\GenerationSubmitter;
use App\Http\Controllers\EstimateController;
use App\Http\Requests\Generations\EstimateGenerationRequest;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Client\CachedPricing;
use App\Quoting\Quoter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

mutates(
    GenerationSubmitter::class,
    Quoter::class,
    CachedPricing::class,
    EstimateController::class,
    EstimateGenerationRequest::class,
    StoreGenerationRequest::class,
);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    Cache::flush();

    $this->user = User::factory()->create();
});

/** The form of the documented worst case: 400 characters, high quality, large size, two references. */
function validGeneration(array $overrides = []): array
{
    return $overrides + [
        'prompt' => str_repeat('a', 400),
        'quality' => 'high',
        'size' => '1536x1024',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ];
}

it('puts a generation in our queue with the price the user was shown', function () {
    $this->actingAs($this->user)->post(route('generations.store'), validGeneration())->assertSessionHasNoErrors();

    $generation = Generation::sole();
    expect($generation->user_id)->toBe($this->user->id)
        ->and($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->prompt)->toBe(str_repeat('a', 400))
        ->and($generation->reference_image_urls)->toBe(['https://example.com/a.png', 'https://example.com/b.png'])
        ->and($generation->markup_percent)->toBe(25)
        ->and($generation->estimated_price_min_nano)->toBe(357_781_250)
        ->and($generation->estimated_price_max_nano)->toBe(467_343_750)
        ->and($generation->estimated_price_min_nano)->toBeLessThanOrEqual($generation->estimated_price_expected_nano)
        ->and($generation->estimated_price_expected_nano)->toBeLessThanOrEqual($generation->estimated_price_max_nano)
        ->and($generation->claimed_at)->toBeNull()
        ->and($generation->uuid)->toBeUuid();
});

it('does not talk to Pixelforge except for the pricing', function () {
    $this->actingAs($this->user)->post(route('generations.store'), validGeneration());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pricing'));
});

it('refuses an invalid generation and stores nothing', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('generations.store'), validGeneration($overrides))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
})->with([
    'no prompt' => [['prompt' => ''], 'prompt'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'unknown size' => [['size' => '10x10'], 'size'],
    'more than four references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'the same reference twice' => [['reference_images' => ['https://example.com/a.png', 'https://example.com/a.png']], 'reference_images.0'],
    'reference that is not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'reference with another scheme' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
    'prompt that is a list' => [['prompt' => ['a']], 'prompt'],
]);

it('stores the references as a plain list, whatever keys they came with', function () {
    $this->actingAs($this->user)->post(route('generations.store'), validGeneration([
        'reference_images' => ['first' => 'https://example.com/a.png', 'second' => 'https://example.com/b.png'],
    ]))->assertSessionHasNoErrors();

    expect(Generation::sole()->reference_image_urls)->toBe(['https://example.com/a.png', 'https://example.com/b.png']);
});

it('accepts the limits themselves', function () {
    $this->actingAs($this->user)->post(route('generations.store'), validGeneration([
        'prompt' => str_repeat('я', 4000),
        'reference_images' => array_map(fn (int $i) => "https://example.com/{$i}.png", range(1, 4)),
    ]))->assertSessionHasNoErrors();

    expect(Generation::sole()->prompt)->toHaveLength(4000);
});

it('works without reference images', function () {
    $this->actingAs($this->user)->post(route('generations.store'), validGeneration(['reference_images' => []]))
        ->assertSessionHasNoErrors();

    expect(Generation::sole()->reference_image_urls)->toBe([]);
});

it('keeps a user from filling the queue', function () {
    Generation::factory()->count(5)->create(['user_id' => $this->user->id]);
    Generation::factory()->failed()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post(route('generations.store'), validGeneration())->assertSessionHasErrors('prompt');

    expect(Generation::count())->toBe(6);
});

it('counts generations running at the provider as well as waiting ones', function (GenerationStatus $status) {
    Generation::factory()->count(5)->create(['user_id' => $this->user->id, 'status' => $status, 'provider_id' => null, 'claimed_at' => now()]);

    $this->actingAs($this->user)->post(route('generations.store'), validGeneration())->assertSessionHasErrors('prompt');
})->with([GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing]);

it('counts only unfinished generations of the same user against the limit', function () {
    Generation::factory()->count(4)->create(['user_id' => $this->user->id]);
    Generation::factory()->count(5)->create(); // other users
    Generation::factory()->succeeded()->count(3)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post(route('generations.store'), validGeneration())->assertSessionHasNoErrors();

    expect(Generation::where('user_id', $this->user->id)->count())->toBe(8);
});

it('stores nothing when billing refuses the reservation', function () {
    $this->mock(Billing::class)->shouldReceive('reserve')->once()->andThrow(new InsufficientBalance('Not enough credits.'));

    $this->actingAs($this->user)->post(route('generations.store'), validGeneration())
        ->assertSessionHasErrors(['prompt' => 'Not enough credits.']);

    expect(Generation::count())->toBe(0);
});

it('reserves the money of the stored generation', function () {
    $this->mock(Billing::class)->shouldReceive('reserve')->once()
        ->withArgs(fn (Generation $generation) => $generation->exists && $generation->user_id === $this->user->id);

    $this->actingAs($this->user)->post(route('generations.store'), validGeneration());
});

it('is for signed-in users only', function () {
    $this->post(route('generations.store'), validGeneration())->assertRedirect(route('login'));
    $this->postJson(route('generations.estimate'), validGeneration())->assertUnauthorized();

    expect(Generation::count())->toBe(0);
});

// expected: 100 text, 1530 image, 6450 output and the middle of the reasoning range (4500) tokens
// = 100 x 5000 + 1530 x 10000 + 6450 x 40000 + 4500 x 12500 = 330_050_000 nanodollars, x 1.25.
it('shows the price range before submitting', function () {
    $this->actingAs($this->user)->postJson(route('generations.estimate'), validGeneration())
        ->assertOk()
        ->assertExactJson([
            'min' => '0.357781250',
            'expected' => '0.412562500',
            'max' => '0.467343750',
            'markup_percent' => 25,
        ]);

    expect(Generation::count())->toBe(0);
});

it('estimates while the prompt is still empty', function () {
    $this->actingAs($this->user)->postJson(route('generations.estimate'), ['quality' => 'low', 'size' => '1024x1024'])
        ->assertOk()
        ->assertJsonStructure(['min', 'expected', 'max', 'markup_percent']);
});

it('validates what the estimate depends on', function (array $overrides, string $field) {
    $this->actingAs($this->user)->postJson(route('generations.estimate'), validGeneration($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'unknown size' => [['size' => '10x10'], 'size'],
    'too many references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'prompt that is a list' => [['prompt' => ['a']], 'prompt'],
    'reference that is not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'the same reference twice' => [['reference_images' => ['https://example.com/a.png', 'https://example.com/a.png']], 'reference_images.0'],
    'reference that is a list' => [['reference_images' => [['https://example.com/a.png']]], 'reference_images.0'],
]);

it('needs the quality and the size, for the estimate and for sending', function (string $field, string $route) {
    $data = validGeneration();
    unset($data[$field]);

    $this->actingAs($this->user)->postJson(route($route), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'quality of the estimate' => ['quality', 'generations.estimate'],
    'size of the estimate' => ['size', 'generations.estimate'],
    'quality of a generation' => ['quality', 'generations.store'],
    'size of a generation' => ['size', 'generations.store'],
]);

it('estimates with an empty prompt and no references, however the browser sends them', function (array $overrides) {
    $this->actingAs($this->user)->postJson(route('generations.estimate'), ['quality' => 'low', 'size' => '1024x1024'] + $overrides)
        ->assertOk();
})->with([
    'empty text' => [['prompt' => '']],
    'null' => [['prompt' => null, 'reference_images' => null]],
    'an empty list' => [['reference_images' => []]],
]);

it('asks Pixelforge for the pricing once, however many estimates are made', function () {
    foreach (range(1, 3) as $ignored) {
        $this->actingAs($this->user)->postJson(route('generations.estimate'), validGeneration())->assertOk();
    }

    Http::assertSentCount(1);
});
