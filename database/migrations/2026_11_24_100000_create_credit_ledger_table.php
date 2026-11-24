<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The credit ledger: one append-only line per money movement of a user. The balance is the sum of
 * the amounts, there is no balance column that could drift. Money is whole nanodollars (1e-9 USD)
 * in bigint, see App\Money\Usd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            // History must survive: neither a user nor a generation with entries can be hard deleted.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['grant', 'purchase', 'hold', 'hold_release', 'charge']);
            // Signed: money in is positive, money out or held is negative.
            $table->bigInteger('amount_nano');
            $table->foreignId('generation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // A generation is held, released and charged at most once each: a replayed webhook
            // cannot move money twice.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE credit_ledger ADD CONSTRAINT credit_ledger_amount_check CHECK (
            (type IN ('grant', 'purchase', 'hold_release') AND amount_nano > 0)
            OR (type = 'hold' AND amount_nano < 0)
            OR (type = 'charge' AND amount_nano <= 0)
        )");
        DB::statement("ALTER TABLE credit_ledger ADD CONSTRAINT credit_ledger_generation_check CHECK (
            (type IN ('grant', 'purchase') AND generation_id IS NULL)
            OR (type IN ('hold', 'hold_release', 'charge') AND generation_id IS NOT NULL)
        )");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION credit_ledger_refuse_change() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'credit_ledger is append-only';
            END;
            \$\$ LANGUAGE plpgsql
        ");
        DB::unprepared('
            CREATE TRIGGER credit_ledger_append_only
            BEFORE UPDATE OR DELETE ON credit_ledger
            FOR EACH ROW EXECUTE FUNCTION credit_ledger_refuse_change()
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
        DB::unprepared('DROP FUNCTION IF EXISTS credit_ledger_refuse_change()');
    }
};
