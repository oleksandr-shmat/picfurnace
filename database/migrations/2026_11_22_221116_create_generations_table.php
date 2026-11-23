<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * All money columns are whole nanodollars (1e-9 USD) in bigint, see App\Money\Usd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Our own id: sent to Pixelforge as the Idempotency-Key and in `metadata`, which is how
            // webhooks are matched back, even one that arrives before the POST response returns.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // On PostgreSQL this is a varchar with a CHECK on the listed values.
            $table->enum('status', ['pending', 'queued', 'processing', 'succeeded', 'failed'])->default('pending');

            // The request.
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->jsonb('reference_image_urls');

            // What the user was shown before submitting. The markup percent is a snapshot, so the
            // estimate and the final charge use the same percent even if the config changes later.
            $table->unsignedSmallInteger('markup_percent');
            $table->bigInteger('estimated_price_min_nano');
            $table->bigInteger('estimated_price_expected_nano');
            $table->bigInteger('estimated_price_max_nano');

            // Pixelforge side. `rates` come from the 202 response and are fixed per generation.
            $table->string('provider_id')->nullable()->unique();
            $table->jsonb('rates')->nullable();
            $table->jsonb('usage')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable();

            // What the user pays. Set once, when the generation reaches a terminal status
            // (0 for a failed generation); null before that.
            $table->bigInteger('charged_nano')->nullable();

            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_expires_at')->nullable();

            // Our own dispatch queue (fairness, backoff on 429/503).
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
        });

        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_money_check CHECK (
            estimated_price_min_nano >= 0
            AND estimated_price_min_nano <= estimated_price_expected_nano
            AND estimated_price_expected_nano <= estimated_price_max_nano
            AND (provider_cost_nano IS NULL OR provider_cost_nano >= 0)
            AND (charged_nano IS NULL OR charged_nano >= 0)
        )');
        // A charge exists only for a terminal generation, a success is billed from its usage and a
        // failure is free.
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_charge_check CHECK (
            (status NOT IN ('succeeded', 'failed') AND charged_nano IS NULL AND provider_cost_nano IS NULL)
            OR (status = 'failed' AND charged_nano = 0 AND provider_cost_nano IS NULL)
            OR (status = 'succeeded' AND charged_nano IS NOT NULL AND provider_cost_nano IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
