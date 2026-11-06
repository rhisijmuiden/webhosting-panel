<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Package limits for Node.js and Python apps (NULL = unlimited, max_webapps 0 = not allowed). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_packages', function (Blueprint $table) {
            $table->unsignedInteger('max_webapps')->nullable();
            $table->unsignedInteger('webapp_memory_mb')->nullable();
            $table->unsignedInteger('webapp_cpu_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hosting_packages', function (Blueprint $table) {
            $table->dropColumn(['max_webapps', 'webapp_memory_mb', 'webapp_cpu_percent']);
        });
    }
};
