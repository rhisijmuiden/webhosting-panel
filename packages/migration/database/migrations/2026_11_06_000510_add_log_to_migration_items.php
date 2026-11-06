<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_items', function (Blueprint $table) {
            // What happened, step by step: [{at, level, text, params, agent?}]; kept after the migration ends.
            $table->json('log')->nullable()->after('notes');
            $table->timestamp('started_at')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('migration_items', function (Blueprint $table) {
            $table->dropColumn(['log', 'started_at']);
        });
    }
};
