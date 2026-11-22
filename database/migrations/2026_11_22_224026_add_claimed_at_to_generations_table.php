<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Set by the dispatcher just before it sends the generation to Pixelforge. From then on
            // the user can no longer cancel it for free, because it may already exist there.
            $table->timestamp('claimed_at')->nullable()->after('next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
