<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\LedgerEntryType;
use App\Generations\GenerationLifecycle;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * The whole money flow with the real ledger: submit holds, then success charges, failure and
 * cancellation give the hold back, and a user without credit cannot submit.
 */

// The documented worst case below has a maximum price of 467_343_750 nano.
const MAX_PRICE_NANO = 467_343_750;
// The recorded `generation_succeeded` response costs 13_830_000 at the provider, 17_287_500 with 25%.
const RECORDED_PRICE_NANO = 17_287_500;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    Cache::flush();

    $this->user = User::factory()->withCredit()->create();
});

function submitWorstCase(User $user): TestResponse
{
    return test()->actingAs($user)->post(route('generations.store'), [
        'prompt' => str_repeat('a', 400),
        'quality' => 'high',
        'size' => '1536x1024',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ]);
}

function balanceNano(User $user): int
{
    return Usd::toInt(app(Billing::class)->balance($user));
}

it('holds the maximum price when a generation is submitted', function () {
    submitWorstCase($this->user);

    $hold = LedgerEntry::where('type', LedgerEntryType::Hold->value)->sole();
    expect($hold->amount_nano)->toBe(-MAX_PRICE_NANO)
        ->and($hold->generation_id)->toBe(Generation::sole()->id)
        ->and(balanceNano($this->user))->toBe(5_000_000_000 - MAX_PRICE_NANO);
});

it('charges the real price and gives the rest of the hold back when a generation succeeds', function () {
    submitWorstCase($this->user);
    $generation = Generation::sole();

    app(GenerationLifecycle::class)->applyProviderSnapshot(
        $generation,
        providerSnapshot('generation_succeeded', ['id' => 'gen_1']),
    );

    expect($generation->refresh()->charged_nano)->toBe(RECORDED_PRICE_NANO)
        ->and(balanceNano($this->user))->toBe(5_000_000_000 - RECORDED_PRICE_NANO);
});

it('gives all the money back when a generation fails', function () {
    submitWorstCase($this->user);

    app(GenerationLifecycle::class)->applyProviderSnapshot(
        Generation::sole(),
        providerSnapshot('generation_failed', ['id' => 'gen_1']),
    );

    expect(balanceNano($this->user))->toBe(5_000_000_000);
});

it('gives all the money back when a generation is cancelled', function () {
    submitWorstCase($this->user);

    app(GenerationLifecycle::class)->cancel(Generation::sole());

    expect(balanceNano($this->user))->toBe(5_000_000_000);
});

it('does not charge twice when the success is reported twice', function () {
    submitWorstCase($this->user);
    $generation = Generation::sole();
    $snapshot = providerSnapshot('generation_succeeded', ['id' => 'gen_1']);

    app(GenerationLifecycle::class)->applyProviderSnapshot($generation, $snapshot);
    app(GenerationLifecycle::class)->applyProviderSnapshot($generation->fresh(), $snapshot);

    expect(balanceNano($this->user))->toBe(5_000_000_000 - RECORDED_PRICE_NANO);
});

it('refuses a user without credit and creates nothing', function () {
    $poor = User::factory()->create();

    submitWorstCase($poor)->assertSessionHasErrors(['prompt' => 'Not enough credit for this generation. Add credit and try again.']);

    expect(Generation::count())->toBe(0)
        ->and(LedgerEntry::where('user_id', $poor->id)->count())->toBe(0);
});
