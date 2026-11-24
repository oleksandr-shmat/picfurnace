<?php

declare(strict_types=1);

/*
 * The money rules of GenerationLifecycle hold because it locks the generation row. One process
 * cannot show that: these tests start a second PHP process while the first holds the row lock,
 * check (in pg_stat_activity) that the second one really waits for the lock, and only then let the
 * first one finish. See helpers.php. The rows are committed for real, so they are deleted again
 * afterwards.
 */

use App\Enums\GenerationStatus;
use App\Generations\GenerationLifecycle;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/helpers.php';

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    DB::rollBack();
    // The ledger refuses DELETE (it is append-only); TRUNCATE is the way to clear committed test rows.
    DB::statement('TRUNCATE credit_ledger');
    Generation::query()->delete();
    User::withTrashed()->forceDelete();
});

it('charges a success once when two reports of it arrive at the same moment', function () {
    $generation = Generation::factory()->create(['claimed_at' => now()]);

    // This process is the one that gets to the row first and is still busy with it ...
    DB::beginTransaction();
    Generation::query()->lockForUpdate()->findOrFail($generation->id);

    // ... when the same success is reported to a second process.
    $other = startOtherProcess('lifecycle_in_child_process.php', 'snapshot', (string) $generation->id, 'event_succeeded');
    waitUntilAProcessWaitsForALock($other);

    $first = app(GenerationLifecycle::class)->applyProviderSnapshot($generation, providerSnapshot('generation_succeeded'));
    DB::commit();

    // The second one now sees a finished generation and changes nothing, so nobody is charged twice.
    expect($first)->toBeTrue()
        ->and(resultOf($other))->toBe(['changed' => false]);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nano)->toBe(17_287_500);
});

it('does not let a cancellation undo a generation that is already being sent', function () {
    $generation = Generation::factory()->create();

    // The dispatcher is claiming the generation to send it to Pixelforge ...
    DB::beginTransaction();
    Generation::query()->lockForUpdate()->findOrFail($generation->id);

    // ... while the user presses Cancel in a second request.
    $other = startOtherProcess('lifecycle_in_child_process.php', 'cancel', (string) $generation->id);
    waitUntilAProcessWaitsForALock($other);

    $generation->update(['claimed_at' => now()]);
    DB::commit();

    // Cancelling it for free now would lose money: it may exist at Pixelforge and be billed.
    expect(resultOf($other))->toBe(['changed' => false]);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->claimed_at)->not->toBeNull()
        ->and($generation->charged_nano)->toBeNull();
});
