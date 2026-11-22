<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Pixelforge `evt_...` id. Duplicates keep the same id, so the unique index is the
            // deduplication: a repeated delivery only bumps `deliveries`.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload');
            $table->unsignedInteger('deliveries')->default(1);
            // What we did with it: applied, ignored_stale (status would move backwards),
            // ignored_unknown_generation.
            $table->string('outcome', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
