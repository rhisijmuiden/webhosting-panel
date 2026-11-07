<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The last known hardening state per WordPress install (the files on the server stay the truth). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_hardening_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->unique()->constrained('wordpress_installs')->cascadeOnDelete();
            $table->json('measures')->nullable();
            $table->unsignedTinyInteger('on_count')->default(0);
            $table->unsignedTinyInteger('total')->default(5);
            $table->timestamp('salts_refreshed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_hardening_states');
    }
};
