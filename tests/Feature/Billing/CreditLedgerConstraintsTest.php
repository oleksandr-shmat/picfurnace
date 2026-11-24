<?php

declare(strict_types=1);

use App\Billing\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores an entry with a signed amount', function () {
    $user = User::factory()->create();

    $entry = LedgerEntry::create([
        'user_id' => $user->id,
        'type' => LedgerEntryType::Grant,
        'amount_nano' => 5_000_000_000,
    ]);

    expect($entry->fresh()->type)->toBe(LedgerEntryType::Grant)
        ->and($entry->fresh()->amount_nano)->toBe(5_000_000_000);
});

it('refuses an amount with the wrong sign for its type', function (LedgerEntryType $type, int $amount) {
    $generation = Generation::factory()->create();

    LedgerEntry::create([
        'user_id' => $generation->user_id,
        'type' => $type,
        'amount_nano' => $amount,
        'generation_id' => $type->belongsToGeneration() ? $generation->id : null,
    ]);
})->throws(QueryException::class, 'credit_ledger_amount_check')->with([
    'grant of zero' => [LedgerEntryType::Grant, 0],
    'negative grant' => [LedgerEntryType::Grant, -1],
    'negative purchase' => [LedgerEntryType::Purchase, -1],
    'positive hold' => [LedgerEntryType::Hold, 1],
    'zero hold' => [LedgerEntryType::Hold, 0],
    'negative hold release' => [LedgerEntryType::HoldRelease, -1],
    'positive charge' => [LedgerEntryType::Charge, 1],
]);

it('allows a free charge', function () {
    $generation = Generation::factory()->create();

    $entry = LedgerEntry::create([
        'user_id' => $generation->user_id,
        'type' => LedgerEntryType::Charge,
        'amount_nano' => 0,
        'generation_id' => $generation->id,
    ]);

    expect($entry->exists)->toBeTrue();
});

it('refuses a generation entry without a generation and a top up with one', function (LedgerEntryType $type, int $amount, bool $withGeneration) {
    $generation = Generation::factory()->create();

    LedgerEntry::create([
        'user_id' => $generation->user_id,
        'type' => $type,
        'amount_nano' => $amount,
        'generation_id' => $withGeneration ? $generation->id : null,
    ]);
})->throws(QueryException::class, 'credit_ledger_generation_check')->with([
    'hold without generation' => [LedgerEntryType::Hold, -1, false],
    'hold release without generation' => [LedgerEntryType::HoldRelease, 1, false],
    'charge without generation' => [LedgerEntryType::Charge, -1, false],
    'grant with generation' => [LedgerEntryType::Grant, 1, true],
    'purchase with generation' => [LedgerEntryType::Purchase, 1, true],
]);

it('allows one entry of each type per generation and refuses a second', function () {
    $generation = Generation::factory()->create();
    $entry = fn (LedgerEntryType $type, int $amount) => LedgerEntry::create([
        'user_id' => $generation->user_id,
        'type' => $type,
        'amount_nano' => $amount,
        'generation_id' => $generation->id,
    ]);

    $entry(LedgerEntryType::Hold, -3_000);
    $entry(LedgerEntryType::HoldRelease, 3_000);
    $entry(LedgerEntryType::Charge, -1_250);

    expect(LedgerEntry::count())->toBe(3);

    $entry(LedgerEntryType::Charge, -1_250);
})->throws(QueryException::class, 'credit_ledger_generation_id_type_unique');

it('refuses an unknown type even when written around the model', function () {
    $user = User::factory()->create();

    DB::insert(
        'insert into credit_ledger (user_id, type, amount_nano, created_at) values (?, ?, ?, now())',
        [$user->id, 'refund', 1],
    );
})->throws(QueryException::class, 'violates check constraint');

it('refuses to change an entry', function () {
    $entry = LedgerEntry::factory()->create();

    DB::update('update credit_ledger set amount_nano = amount_nano + 1 where id = ?', [$entry->id]);
})->throws(QueryException::class, 'credit_ledger is append-only');

it('refuses to delete an entry', function () {
    $entry = LedgerEntry::factory()->create();

    DB::delete('delete from credit_ledger where id = ?', [$entry->id]);
})->throws(QueryException::class, 'credit_ledger is append-only');

it('keeps the history of a user: the database refuses to hard delete a user with entries', function () {
    $entry = LedgerEntry::factory()->create();

    DB::delete('delete from users where id = ?', [$entry->user_id]);
})->throws(QueryException::class, 'credit_ledger_user_id_foreign');

it('keeps the history of a generation: the database refuses to hard delete one with entries', function () {
    $generation = Generation::factory()->create();
    LedgerEntry::factory()->charge($generation)->create();

    DB::delete('delete from generations where id = ?', [$generation->id]);
})->throws(QueryException::class, 'credit_ledger_generation_id_foreign');
