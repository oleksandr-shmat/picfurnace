<?php

declare(strict_types=1);

/*
 * The money rules of GenerationLifecycle hold because it locks the generation row. One process
 * cannot show that: these tests start a second PHP process while the first holds the row lock,
 * check (in pg_stat_activity) that the second one really waits for the lock, and only then let the
 * first one finish. The rows are committed for real, so they are deleted again afterwards.
 */

use App\Enums\GenerationStatus;
use App\Generations\GenerationLifecycle;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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

/**
 * Starts the second process. It prints its result when it finishes.
 *
 * @return array{process: resource, pipe: resource}
 */
function startOtherProcess(string $action, int $generationId, ?string $fixture = null): array
{
    $database = config('database.connections.pgsql');
    $command = array_filter([PHP_BINARY, base_path('tests/Support/lifecycle_in_child_process.php'), $action, (string) $generationId, $fixture]);

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [
        'PATH' => getenv('PATH'),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => $database['database'],
        'DB_USERNAME' => $database['username'],
        'DB_PASSWORD' => $database['password'],
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ]);

    return ['process' => $process, 'pipe' => $pipes[1], 'errors' => $pipes[2]];
}

/**
 * Waits until the other process is stuck waiting for a database lock.
 *
 * @param  array{process: resource, pipe: resource, errors: resource}  $other
 */
function waitUntilAProcessWaitsForALock(array $other): void
{
    for ($i = 0; $i < 100; $i++) {
        // PostgreSQL keeps what pg_stat_activity showed for as long as a transaction lasts, and this
        // process is inside one: without this it would keep seeing the first, empty answer.
        DB::select('select pg_stat_clear_snapshot()');

        $waiting = DB::scalar(
            "select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'",
        );

        if ($waiting > 0) {
            return;
        }

        usleep(100_000);
    }

    $status = proc_get_status($other['process']);
    stream_set_blocking($other['pipe'], false);
    stream_set_blocking($other['errors'], false);

    $activity = DB::select('select datname, state, wait_event_type, wait_event, left(query, 60) as query from pg_stat_activity where datname is not null');

    test()->fail(sprintf(
        "The second process never waited for the row lock (running: %s, exit code: %d).\nOutput: %s\nErrors: %s\nActivity: %s",
        $status['running'] ? 'yes' : 'no',
        $status['exitcode'],
        stream_get_contents($other['pipe']),
        stream_get_contents($other['errors']),
        json_encode($activity),
    ));
}

/**
 * @param  array{process: resource, pipe: resource, errors: resource}  $other
 * @return array{changed: bool}
 */
function resultOf(array $other): array
{
    $output = stream_get_contents($other['pipe']);
    proc_close($other['process']);

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

it('charges a success once when two reports of it arrive at the same moment', function () {
    $generation = Generation::factory()->create(['claimed_at' => now()]);

    // This process is the one that gets to the row first and is still busy with it ...
    DB::beginTransaction();
    Generation::query()->lockForUpdate()->findOrFail($generation->id);

    // ... when the same success is reported to a second process.
    $other = startOtherProcess('snapshot', $generation->id, 'event_succeeded');
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
    $other = startOtherProcess('cancel', $generation->id);
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
