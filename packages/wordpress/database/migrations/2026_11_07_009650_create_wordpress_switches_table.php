<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Last known state of the WordPress quick switches (maintenance, debug, indexing) per install. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_switches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->unique()->constrained('wordpress_installs')->cascadeOnDelete();
            $table->boolean('maintenance')->default(false);
            $table->timestamp('maintenance_since')->nullable();
            $table->string('maintenance_title', 120)->nullable();
            $table->text('maintenance_message')->nullable();
            $table->boolean('debug')->default(false);
            $table->string('debug_log', 16)->nullable(); // panel | public | custom
            $table->timestamp('debug_until')->nullable(); // switched off automatically after this moment
            $table->boolean('indexing')->default(true);
            $table->unsignedBigInteger('log_size')->default(0);
            $table->unsignedBigInteger('public_log_size')->nullable(); // a wp-content/debug.log anybody can download
            $table->timestamp('cache_cleared_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_switches');
    }
};
