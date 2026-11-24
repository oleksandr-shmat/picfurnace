<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\LedgerEntryType;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Requests\PurchaseCreditRequest;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

mutates(CreditPurchaseController::class, PurchaseCreditRequest::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    Cache::flush();

    $this->user = User::factory()->create();
});

it('adds the credit of a pack to the balance', function (int $pack) {
    $this->actingAs($this->user)->post(route('credits.store'), ['pack' => $pack])
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Added \${$pack} of demo credit. No real payment was made."]);

    $entry = LedgerEntry::sole();
    expect($entry->type)->toBe(LedgerEntryType::Purchase)
        ->and($entry->user_id)->toBe($this->user->id)
        ->and($entry->amount_nano)->toBe($pack * 1_000_000_000)
        ->and($entry->generation_id)->toBeNull()
        ->and(app(Billing::class)->balance($this->user)->equals(Usd::nano($pack * 1_000_000_000)))->toBeTrue();
})->with([5, 10, 20]);

it('adds to what is already there', function () {
    LedgerEntry::factory()->create(['user_id' => $this->user->id, 'amount_nano' => 1_500_000_000]);

    $this->actingAs($this->user)->post(route('credits.store'), ['pack' => 10]);

    expect(app(Billing::class)->balance($this->user)->equals(Usd::nano(11_500_000_000)))->toBeTrue();
});

it('refuses an amount that is not a pack', function (mixed $pack) {
    $this->actingAs($this->user)->post(route('credits.store'), ['pack' => $pack])
        ->assertSessionHasErrors('pack');

    expect(LedgerEntry::count())->toBe(0);
})->with([
    'another amount' => [7],
    'zero' => [0],
    'negative' => [-5],
    'a decimal' => ['5.5'],
    'not a number' => ['abc'],
    'empty' => [''],
    'missing' => [null],
    'a list' => [[5]],
]);

it('refuses a request without a pack at all', function () {
    $this->actingAs($this->user)->post(route('credits.store'), [])->assertSessionHasErrors('pack');

    expect(LedgerEntry::count())->toBe(0);
});

it('takes the packs from the configuration', function () {
    config(['billing.credit_packs' => [3]]);

    $this->actingAs($this->user)->post(route('credits.store'), ['pack' => 3])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('credits.store'), ['pack' => 5])->assertSessionHasErrors('pack');
});

it('is for signed-in users only', function () {
    $this->post(route('credits.store'), ['pack' => 5])->assertRedirect(route('login'));

    expect(LedgerEntry::count())->toBe(0);
});

it('shows the balance and the packs on the Studio page', function () {
    LedgerEntry::factory()->create(['user_id' => $this->user->id, 'amount_nano' => 5_000_000_000]);

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('balance', '5.000000000')
        ->where('creditPacks', [5, 10, 20]));
});

it('shows a negative balance after a generation cost more than was held', function () {
    $generation = Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);
    LedgerEntry::factory()->charge($generation, 2_500_000_000)->create();

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', '-2.500000000'));
});

it('shows only the own balance', function () {
    LedgerEntry::factory()->create(['amount_nano' => 9_000_000_000]);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', '0.000000000'));
});

it('lets a user who had no credit submit after buying some', function () {
    $form = ['prompt' => 'a cat', 'quality' => 'low', 'size' => '1024x1024', 'reference_images' => []];
    $this->actingAs($this->user)->post(route('generations.store'), $form)->assertSessionHasErrors('prompt');

    $this->actingAs($this->user)->post(route('credits.store'), ['pack' => 5]);

    $this->actingAs($this->user)->post(route('generations.store'), $form)->assertSessionHasNoErrors();
    expect(Generation::count())->toBe(1);
});
