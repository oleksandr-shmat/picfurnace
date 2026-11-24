<?php

declare(strict_types=1);

/*
 * The balance check of a submit is correct because it runs under the lock of the user row. One
 * process cannot show that, so each test lets a second process arrive while the first is in the
 * middle of its work, makes sure the second really waits for a lock, and only then lets the first
 * finish. See helpers.php.
 */

use App\Billing\LedgerBilling;
use App\Generations\GenerationSubmitter;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;
use App\Quoting\Quoter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

require_once __DIR__.'/helpers.php';

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Http::fake(['*/pricing' => Http::response(pixelforgeFixture('pricing'))]);
});

afterEach(function () {
    DB::rollBack();
    // The ledger refuses DELETE (it is append-only); TRUNCATE is the way to clear committed test rows.
    DB::statement('TRUNCATE credit_ledger');
    Generation::query()->delete();
    User::withTrashed()->forceDelete();
});

/** What a generation of the test (a short prompt, low quality) can cost at most, which is what a submit holds. */
function maxPrice(): int
{
    return Usd::toInt(app(Quoter::class)->quote('a cat', 'low', '1024x1024', 0)->price->max);
}

it('lets only one of two submits of the same user at the same moment hold the credit', function () {
    $max = maxPrice();
    $user = User::factory()->create();
    // Enough for one hold, not for two.
    LedgerEntry::factory()->create(['user_id' => $user->id, 'amount_nano' => $max + 1_000]);

    // The first request has submitted and held its part, but its transaction is not finished ...
    DB::beginTransaction();
    app(GenerationSubmitter::class)->submit($user, 'a cat', 'low', '1024x1024', []);

    // ... when the same user's second request arrives (a second browser tab).
    $other = startOtherProcess('submit_in_child_process.php', (string) $user->id);
    waitUntilAProcessWaitsForALock($other);
    DB::commit();

    // The second one read the balance only after the first had held its part, so it was refused.
    expect(resultOf($other))->toBe(['result' => 'insufficient'])
        ->and(Generation::count())->toBe(1)
        ->and(LedgerEntry::where('type', 'hold')->count())->toBe(1);
});

it('makes a submit wait for a charge that is being written, so it sees the balance after it', function () {
    $max = maxPrice();
    $user = User::factory()->create();
    LedgerEntry::factory()->create(['user_id' => $user->id, 'amount_nano' => 2 * $max]);
    $running = Generation::factory()->create([
        'user_id' => $user->id,
        'estimated_price_min_nano' => $max,
        'estimated_price_expected_nano' => $max,
        'estimated_price_max_nano' => $max,
    ]);
    (new LedgerBilling)->reserve($running);
    // The balance is $max now: enough for one more submit, until the running one is charged.

    // A generation finishes for twice what was held. Its lines are written but not committed ...
    DB::beginTransaction();
    (new LedgerBilling)->settle($running, Usd::nano(2 * $max));

    // ... when the same user submits a new one.
    $other = startOtherProcess('submit_in_child_process.php', (string) $user->id);
    waitUntilAProcessWaitsForALock($other);
    DB::commit();

    // It waited for the charge and saw the balance after it (zero), instead of the one before it.
    expect(resultOf($other))->toBe(['result' => 'insufficient'])
        ->and(Generation::count())->toBe(1);
});
