<?php

declare(strict_types=1);

use App\Billing\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Database\Migrations\Migration;

/*
 * Generations finished before the ledger existed were charged only on their own row. The backfill
 * migration writes their charges into the ledger, so the ledger and the generations agree.
 */
function backfillMigration(): Migration
{
    return require database_path('migrations/2026_11_24_100001_backfill_ledger_charges.php');
}

it('writes a charge for every succeeded generation', function () {
    $first = Generation::factory()->succeeded(1_000, 1_250)->create(['completed_at' => '2026-11-23 10:00:00']);
    $second = Generation::factory()->succeeded(40_000, 50_000)->create();

    backfillMigration()->up();

    $entry = LedgerEntry::where('generation_id', $first->id)->sole();
    expect($entry->type)->toBe(LedgerEntryType::Charge)
        ->and($entry->user_id)->toBe($first->user_id)
        ->and($entry->amount_nano)->toBe(-1_250)
        ->and($entry->created_at->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and(LedgerEntry::where('generation_id', $second->id)->sole()->amount_nano)->toBe(-50_000);
});

it('skips generations that cost nothing yet or nothing at all', function () {
    Generation::factory()->create();
    Generation::factory()->failed()->create();

    backfillMigration()->up();

    expect(LedgerEntry::count())->toBe(0);
});

it('does not write a charge twice', function () {
    Generation::factory()->succeeded()->create();

    backfillMigration()->up();
    backfillMigration()->up();

    expect(LedgerEntry::count())->toBe(1);
});

it('keeps an existing charge as it is', function () {
    $generation = Generation::factory()->succeeded(1_000, 1_250)->create();
    LedgerEntry::factory()->charge($generation, 1_250)->create();

    backfillMigration()->up();

    expect(LedgerEntry::count())->toBe(1);
});
