<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Our own copy of the image: Pixelforge links stop working 24 hours after completion.
            $table->string('image_path')->nullable()->after('image_expires_at');
            $table->timestamp('image_stored_at')->nullable()->after('image_path');
            // The copy was deleted after the retention period; the generation itself stays.
            $table->timestamp('image_purged_at')->nullable()->after('image_stored_at');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_stored_at', 'image_purged_at']);
        });
    }
};
