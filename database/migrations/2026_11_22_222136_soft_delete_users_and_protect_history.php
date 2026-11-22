<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Generations and their webhook events are the billing history: provider cost must keep matching
 * GET /v1/usage after a user leaves. Users are therefore soft deleted, and the database refuses
 * to hard delete a user or a generation that still has history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropForeign(['generation_id']);
            $table->foreign('generation_id')->references('id')->on('generations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropForeign(['generation_id']);
            $table->foreign('generation_id')->references('id')->on('generations')->nullOnDelete();
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
