<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Before the ledger, a charge lived only on the generation row (`charged_nano`). Write those
 * charges into the ledger, so that the ledger and the generations agree from the first day.
 * Only succeeded generations: a failed one is free and a running one has no charge yet.
 * Safe to run twice: a generation that already has a charge is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO credit_ledger (user_id, type, amount_nano, generation_id, note, created_at)
            SELECT user_id, 'charge', -charged_nano, id, 'backfilled', COALESCE(completed_at, created_at)
            FROM generations
            WHERE status = 'succeeded'
            ON CONFLICT (generation_id, type) DO NOTHING
        ");
    }

    public function down(): void
    {
        // Entries are append-only, nothing to take back.
    }
};
