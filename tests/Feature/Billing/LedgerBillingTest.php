<?php

declare(strict_types=1);

use App\Billing\InsufficientBalance;
use App\Billing\LedgerBilling;
use App\Billing\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;

mutates(LedgerBilling::class);

// The default test generation has an estimated maximum price of 3 000 nano.

/** A generation whose user has this balance: a grant, an old charge when negative, nothing at zero. */
function fundedGeneration(int $balanceNano): Generation
{
    $generation = Generation::factory()->create();

    if ($balanceNano > 0) {
        LedgerEntry::factory()->create(['user_id' => $generation->user_id, 'amount_nano' => $balanceNano]);
    } elseif ($balanceNano < 0) {
        $earlier = Generation::factory()->create(['user_id' => $generation->user_id]);
        LedgerEntry::factory()->charge($earlier, -$balanceNano)->create();
    }

    return $generation;
}

/** @return list<int> amounts of the entries of a type, oldest first */
function amountsOf(Generation $generation, LedgerEntryType $type): array
{
    return LedgerEntry::query()
        ->where('generation_id', $generation->id)
        ->where('type', $type->value)
        ->orderBy('id')
        ->pluck('amount_nano')
        ->all();
}

it('sums the entries of one user into a balance', function () {
    $user = User::factory()->create();
    LedgerEntry::factory()->create(['user_id' => $user->id, 'amount_nano' => 5_000]);
    LedgerEntry::factory()->create(['user_id' => $user->id, 'type' => LedgerEntryType::Purchase, 'amount_nano' => 2_000]);
    LedgerEntry::factory()->create(['amount_nano' => 9_000]);
    $generation = Generation::factory()->create(['user_id' => $user->id]);
    LedgerEntry::factory()->charge($generation, 1_250)->create();

    expect((new LedgerBilling)->balance($user)->equals(Usd::nano(5_750)))->toBeTrue();
});

it('adds credit as a purchase that belongs to no generation', function () {
    $user = User::factory()->create();

    (new LedgerBilling)->addCredit($user, Usd::nano(10_000_000_000));

    $entry = LedgerEntry::sole();
    expect($entry->type)->toBe(LedgerEntryType::Purchase)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->amount_nano)->toBe(10_000_000_000)
        ->and($entry->generation_id)->toBeNull();
});

it('has a zero balance without entries', function () {
    expect((new LedgerBilling)->balance(User::factory()->create())->isZero())->toBeTrue();
});

it('holds the maximum price when a generation is reserved', function () {
    $generation = fundedGeneration(10_000);

    (new LedgerBilling)->reserve($generation);

    expect(amountsOf($generation, LedgerEntryType::Hold))->toBe([-3_000])
        ->and((new LedgerBilling)->balance($generation->user)->equals(Usd::nano(7_000)))->toBeTrue();
});

it('reserves when the balance equals the maximum price exactly', function () {
    $generation = fundedGeneration(3_000);

    (new LedgerBilling)->reserve($generation);

    expect(amountsOf($generation, LedgerEntryType::Hold))->toBe([-3_000]);
});

it('refuses to reserve when the balance is below the maximum price', function (int $balance) {
    $generation = fundedGeneration($balance);

    expect(fn () => (new LedgerBilling)->reserve($generation))->toThrow(InsufficientBalance::class);
    expect(LedgerEntry::where('type', LedgerEntryType::Hold->value)->count())->toBe(0);
})->with([
    'one nano short' => [2_999],
    'empty' => [0],
    'negative after an overrun' => [-1],
]);

it('counts the held money when the next generation is reserved', function () {
    $first = fundedGeneration(4_000);
    $second = Generation::factory()->create(['user_id' => $first->user_id]);
    (new LedgerBilling)->reserve($first);

    expect(fn () => (new LedgerBilling)->reserve($second))->toThrow(InsufficientBalance::class);
});

it('turns the hold into the charge when a generation is settled', function () {
    $generation = fundedGeneration(10_000);
    $billing = new LedgerBilling;
    $billing->reserve($generation);

    $billing->settle($generation, Usd::nano(1_250));

    expect(amountsOf($generation, LedgerEntryType::HoldRelease))->toBe([3_000])
        ->and(amountsOf($generation, LedgerEntryType::Charge))->toBe([-1_250])
        ->and($billing->balance($generation->user)->equals(Usd::nano(8_750)))->toBeTrue();
});

it('charges the full price when it is above the hold and flags it', function () {
    $generation = fundedGeneration(3_000);
    $billing = new LedgerBilling;
    $billing->reserve($generation);

    $billing->settle($generation, Usd::nano(5_000));

    $charge = LedgerEntry::where('generation_id', $generation->id)->where('type', LedgerEntryType::Charge->value)->sole();
    expect($charge->amount_nano)->toBe(-5_000)
        ->and($charge->note)->toBe('overrun')
        ->and($billing->balance($generation->user)->equals(Usd::nano(-2_000)))->toBeTrue();
});

it('does not flag a charge that equals the hold', function () {
    $generation = fundedGeneration(3_000);
    $billing = new LedgerBilling;
    $billing->reserve($generation);

    $billing->settle($generation, Usd::nano(3_000));

    expect(LedgerEntry::where('type', LedgerEntryType::Charge->value)->sole()->note)->toBeNull();
});

it('only charges a generation that has no hold', function () {
    $generation = fundedGeneration(10_000);

    (new LedgerBilling)->settle($generation, Usd::nano(1_250));

    expect(amountsOf($generation, LedgerEntryType::Hold))->toBe([])
        ->and(amountsOf($generation, LedgerEntryType::HoldRelease))->toBe([])
        ->and(amountsOf($generation, LedgerEntryType::Charge))->toBe([-1_250])
        ->and((new LedgerBilling)->balance($generation->user)->equals(Usd::nano(8_750)))->toBeTrue();
});

it('does not move money twice when a generation is settled again', function () {
    $generation = fundedGeneration(10_000);
    $billing = new LedgerBilling;
    $billing->reserve($generation);

    $billing->settle($generation, Usd::nano(1_250));
    $billing->settle($generation, Usd::nano(1_250));

    expect(amountsOf($generation, LedgerEntryType::HoldRelease))->toBe([3_000])
        ->and(amountsOf($generation, LedgerEntryType::Charge))->toBe([-1_250]);
});

it('gives the hold back when a generation is released', function () {
    $generation = fundedGeneration(10_000);
    $billing = new LedgerBilling;
    $billing->reserve($generation);

    $billing->release($generation);

    expect(amountsOf($generation, LedgerEntryType::HoldRelease))->toBe([3_000])
        ->and(amountsOf($generation, LedgerEntryType::Charge))->toBe([])
        ->and($billing->balance($generation->user)->equals(Usd::nano(10_000)))->toBeTrue();
});

it('writes nothing when a generation without a hold is released', function () {
    $generation = fundedGeneration(10_000);

    (new LedgerBilling)->release($generation);

    expect(LedgerEntry::count())->toBe(1);
});

it('does not give the hold back twice', function () {
    $generation = fundedGeneration(10_000);
    $billing = new LedgerBilling;
    $billing->reserve($generation);

    $billing->release($generation);
    $billing->release($generation);

    expect(amountsOf($generation, LedgerEntryType::HoldRelease))->toBe([3_000]);
});
