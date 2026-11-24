<?php

declare(strict_types=1);

/*
 * Shared by the concurrency tests. Only a second process has a second database connection, which
 * is what it takes to see a lock work: the test starts one while this process holds a lock, checks
 * in pg_stat_activity that the second one really waits, and only then lets the first one finish.
 */

use Illuminate\Support\Facades\DB;

/**
 * Starts a script from tests/Support in a process of its own. It prints its result when it ends.
 *
 * @return array{process: resource, pipe: resource, errors: resource}
 */
function startOtherProcess(string $script, string ...$arguments): array
{
    $database = config('database.connections.pgsql');

    $process = proc_open([PHP_BINARY, base_path("tests/Support/{$script}"), ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [
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
        'QUEUE_CONNECTION' => 'null',
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
        "The second process never waited for the lock (running: %s, exit code: %d).\nOutput: %s\nErrors: %s\nActivity: %s",
        $status['running'] ? 'yes' : 'no',
        $status['exitcode'],
        stream_get_contents($other['pipe']),
        stream_get_contents($other['errors']),
        json_encode($activity),
    ));
}

/**
 * What the other process printed, as JSON, once it has finished.
 *
 * @param  array{process: resource, pipe: resource, errors: resource}  $other
 * @return array<string, mixed>
 */
function resultOf(array $other): array
{
    $output = stream_get_contents($other['pipe']);
    proc_close($other['process']);

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}
